<?php

namespace lambda\background_task\handlers;

use api_web\helpers\Excel;
use api_web\helpers\WebApiHelper;
use common\components\resourcemanager\AmazonS3ResourceManager;
use common\helpers\ArrayHelper;
use common\models\SomeClass;
use DateTime;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Yii;
use yii\db\Expression;
use yii\db\Query;
use api_web\classes\SomeWebApi;
use yii\web\BadRequestHttpException;

class AnalyticsReportHandler extends BaseHandler
{
    public $removeAfterSuccessExecute = true;

    /**
     * Таблица Excel
     *
     * @var Spreadsheet
     */
    private $objPHPExcel;

    /**
     * @var array
     */
    public $request = [];

    /**
     * ID текущей организации
     *
     * @var User
     */
    private $user;

    /**
     * Данные для третьего листа/верстки email уведомления
     */
    private $thirdListData;

    /**
     * Дата из фильтра "от"
     *
     * @var string
     */
    private $fromDate;

    /**
     * Дата из фильтра "до"
     *
     * @var string
     */
    private $toDate;

    private const DOWNLOAD_LINK = 'download/Link';

    /**
     * @return void
     * @throws \Exception
     */
    public function execute()
    {
        try {
            Yii::$app->cache->delete(SomeClass::REDIS_KEY_ERROR . $this->task->data['orgId']);

            $this->user = SomeClass::findOne(['id' => $this->task->data['userId']]);

            /** @var SomeClass $organization */
            $organization = SomeClass::findOne(['id' => $this->task->data['orgId']]);
            $this->request = array_merge(
                $this->task->data['request'],
                [
                    'user_id'     => $this->user->id,
                    'business_id' => $organization->business_id,
                ]
            );

            $this->fromDate = $this->request['search']['date']['from'] ?? date('Y-m-d', strtotime('-2 weeks'));
            $this->toDate = $this->request['search']['date']['to'] ?? date('Y-m-d');

            $this->createExcel();

            $fileName = md5(uniqid($this->user->email . $this->task->data['orgId'], true));
            $excelData = Excel::getFileContent(IOFactory::createWriter($this->objPHPExcel, 'Xlsx'));
            $jsonData = json_encode(['request' => $this->task->data['request'], 'response' => $this->thirdListData], JSON_THROW_ON_ERROR);

            $this->saveDataToS3($fileName . '.xlsx', $excelData);
            $this->saveDataToS3($fileName . '.json', $jsonData);

            $downloadUrl = Yii::$app->params['api_web_url'] . self::DOWNLOAD_LINK . $fileName . '.xlsx';

            $this->sendEmail($downloadUrl);

            $this->deleteFromS3(
                Yii::$app->cache->get(SomeClass::REDIS_KEY_FILENAME . $this->task->data['orgId'])
            );

            Yii::$app->cache->set(
                SomeClass::REDIS_KEY_FILENAME . $this->task->data['orgId'],
                $fileName . '.json',
                31536000
            );
        } catch (\Exception $exception) {
            Yii::error($exception->getMessage(), 'profiler');
        } finally {
            Yii::$app->cache->delete(SomeClass::REDIS_KEY_START_DATE_REPORT . $this->task->data['orgId']);
        }
    }

    public static function getFilterNames($request): array
    {
        $employer = $request['search']['employee_id'] ?? null;
        $currency = $request['search']['currency_id'] ?? SomeClass::CURRENCY_ID;
        $nomenclature = $request['search']['nomenclature'] ?? null;
        $vendors = $request['search']['vendor_id'] ?? null;
        $organizations = $request['search']['clients'] ?? null;

        $filtersQuery = (new Query())
            ->select(
                [
                    'filter_key'   => new Expression("'employer'"),
                    'filter_value' => new Expression("string_agg(coalesce(p.full_name, u.email), ', ')"),
                ]
            )
            ->from(['u' => SomeClass::tableNameWithSchema()])
            ->innerJoin(['p' => SomeClass::tableNameWithSchema()], 'p.user_id = u.id')
            ->where(['u.id' => $employer])
            ->union(
                (new Query())
                    ->select(
                        [
                            'filter_key'   => new Expression("'currency'"),
                            'filter_value' => new Expression("string_agg(symbol, ', ')"),
                        ]
                    )
                    ->from(SomeClass::tableNameWithSchema())
                    ->where(['id' => $currency])
            )
            ->union(
                (new Query())
                    ->select(
                        [
                            'filter_key'   => new Expression("'products'"),
                            'filter_value' => new Expression("string_agg(\"name\", ', ')"),
                        ]
                    )
                    ->from(SomeClass::tableNameWithSchema())
                    ->where(['id' => $nomenclature])
            )
            ->union(
                (new Query())
                    ->select(
                        [
                            'filter_key'   => new Expression("'vendors'"),
                            'filter_value' => new Expression("string_agg(\"name\", ', ')"),
                        ]
                    )
                    ->from(SomeClass::tableNameWithSchema())
                    ->where(['id' => $vendors])
            )
            ->union(
                (new Query())
                    ->select(
                        [
                            'filter_key'   => new Expression("'organizations'"),
                            'filter_value' => new Expression("string_agg(\"name\", ', ')"),
                        ]
                    )
                    ->from(SomeClass::tableNameWithSchema())
                    ->where(['id' => $organizations])
            )
            ->all();

        $filters = [];

        foreach ($filtersQuery as $filter) {
            $filters[$filter['filter_key']] = $filter['filter_value'];
        }

        return $filters;
    }

    /**
     * @throws \Exception
     */
    private function sendEmail($downloadUrl)
    {
        $emailFilters = self::getFilterNames($this->request);

        date_default_timezone_set('GMT');

        $mailer = Yii::$app->mailer;
        $mailer->htmlLayout = '@common/way';
        $subject = Yii::t('app', 'report', [], $this->user->language);
        $dateTime = WebApiHelper::addGmtToDate(date('Y-m-d H:i:s'), $user->organization->gmt ?? 0, false, 'd.m.Y, H:i');

        $mailer->setLanguage($this->user->language);
        $mailer->compose(
            '@common/way',
            [
                'dateTime'     => $dateTime,
                'downloadUrl'  => $downloadUrl,
                'reportList'   => $this->thirdListData,
                'fromDate'     => (new DateTime($this->fromDate))->format('d.m.Y'),
                'toDate'       => (new DateTime($this->toDate))->format('d.m.Y'),
                'emailFilters' => $emailFilters,
                'user'         => $this->user
            ]
        )
            ->setTo($this->user->email)
            ->setSubject($subject)
            ->send();
    }

    /**
     * @throws \Exception
     */
    private function saveDataToS3($fileName, $data): void
    {
        /** @var  AmazonS3ResourceManager $s3 */
        $s3 = Yii::$app->resourceManager;

        try {
            $s3->putObject(SomeClass::BUCKET_DIRECTORY . DIRECTORY_SEPARATOR . $fileName, $data);
        } catch (\Exception $e) {
            Yii::$app->cache->set(
                SomeClass::REDIS_KEY_ERROR . $this->task->data['orgId'],
                Yii::t('api_web', 'save_file_error') . $e->getMessage(),
                86400
            );
        }
    }

    private function deleteFromS3($fileName): void
    {
        /** @var  AmazonS3ResourceManager $s3 */
        $s3 = Yii::$app->resourceManager;
        $s3->delete(SomeClass::BUCKET_DIRECTORY . DIRECTORY_SEPARATOR . $fileName);
    }

    /**
     * @throws Exception
     */
    private function createExcel()
    {
        $this->objPHPExcel = new Spreadsheet();

        try {
            $this->objPHPExcel->getProperties()
                ->setCreator("Name")
                ->setLastModifiedBy("Name")
                ->setTitle("Name");

            $this->createFirstSheet();
            $this->createSecondSheet();
            $this->createThirdSheet();

            $this->objPHPExcel->setActiveSheetIndex(0);
        } catch (\Exception $e) {
            Yii::$app->cache->set(
                SomeClass::REDIS_KEY_ERROR . $this->task->data['orgId'],
                Yii::t('api_web', 'create_file_error') . $e->getMessage(),
                86400
            );
        }

        $this->objPHPExcel->setActiveSheetIndex(0);
    }

    /**
     * @return void
     * @throws Exception
     * @throws BadRequestHttpException
     */
    private function createFirstSheet(): void
    {
        $this->objPHPExcel->setActiveSheetIndex(0);
        $activeSheet = $this->objPHPExcel->getActiveSheet();
        $activeSheet->setTitle('Name');

        $activeSheet->getStyle('A1:K2')
            ->applyFromArray(
                [
                    'font'      =>
                        [
                            'name' => 'Arial',
                            'size' => 10,
                            'bold' => true,
                        ],
                    'alignment' =>
                        [
                            'wrapText'   => true,
                            'horizontal' => Alignment::HORIZONTAL_LEFT,
                            'vertical'   => Alignment::VERTICAL_BOTTOM,
                        ],
                ]
            );

        $activeSheet->getStyle('A1:K2')
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFD9D9D9');

        $activeSheet->getStyle('A1:K2')
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $activeSheet->getRowDimension('1')
            ->setRowHeight(45);
        $activeSheet->getRowDimension('2')
            ->setRowHeight(45);
        $activeSheet->getColumnDimension('A')
            ->setWidth(30);
        $activeSheet->getColumnDimension('B')
            ->setWidth(13);
        $activeSheet->getColumnDimension('C')
            ->setWidth(25);
        $activeSheet->getColumnDimension('D')
            ->setWidth(30);

        foreach (range('E', 'K') as $columnID) {
            $activeSheet->getColumnDimension($columnID)
                ->setWidth(12);
        }

        $activeSheet->mergeCells('E1:G1');
        $activeSheet->mergeCells('H1:J1');

        $activeSheet->getStyle('E1:K1')
            ->applyFromArray(['font' => ['size' => 12]]);

        $activeSheet->setCellValue('A1', 'Name');
        $activeSheet->setCellValue('C1', 'Name');
        $activeSheet->setCellValue('E1', 'Name');
        $activeSheet->setCellValue('H1', 'Name');
        $activeSheet->setCellValue('K1', 'Name');
        $activeSheet->setCellValue('A2', 'Name');
        $activeSheet->setCellValue('B2', 'Name');
        $activeSheet->setCellValue('C2', 'Name');
        $activeSheet->setCellValue('D2', 'Name');
        $activeSheet->setCellValue('E2', 'Name');
        $activeSheet->setCellValue('F2', 'Name');
        $activeSheet->setCellValue('G2', 'Name');
        $activeSheet->setCellValue('H2', 'Name');
        $activeSheet->setCellValue('I2', 'Name');
        $activeSheet->setCellValue('J2', 'Name');
        $activeSheet->setCellValue('K2', 'Name');

        $buyColumn = 'E';
        $firstListData = SomeClass::reportQuery($this->request)->all();
        $suppIdAndColumn = [];
        $row = 2;

        $activeSheet->setCellValue('B1', $this->fromDate . PHP_EOL . ' - ' . PHP_EOL . $this->toDate)
            ->getStyle('B1')
            ->applyFromArray(
                [
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'font'      => ['size' => 12]
                ]
            );

        foreach ($firstListData as $item) {
            $row++;
            $column = $buyColumn;

            $activeSheet->getRowDimension($row)
                ->setRowHeight(40);

            $activeSheet->setCellValue('A' . $row, $item['product_name']);
            $activeSheet->setCellValue('B' . $row, $item['unit_names']);
            $activeSheet->setCellValue('C' . $row, $item['store_name']);
            $activeSheet->setCellValue('D' . $row, $item['name_analogs_group']);

            $suppliers = json_decode($item['supp_id_and_price'], true);

            if (!empty($suppliers)) {
                foreach ($suppliers as $suppId => $minPrice) {
                    if (!ArrayHelper::isIn($suppId, array_keys($suppIdAndColumn))) {
                        $activeSheet->insertNewColumnBefore($column);
                        $suppIdAndColumn[$suppId] = $column;
                        $buyColumn++;
                        $column++;
                    }

                    $activeSheet->setCellValue($suppIdAndColumn[$suppId] . $row, round($minPrice, SomeClass::ROUND));
                    $activeSheet->getColumnDimension($suppIdAndColumn[$suppId])
                        ->setWidth(25);
                }
            }

            $activeSheet->setCellValue($column++ . $row, round($item['inc_avg_price_for_unit'],SomeClass::ROUND));
            $activeSheet->setCellValue($column++ . $row, $item['inc_quantity']);
            $activeSheet->setCellValue($column++ . $row, round($item['inc_sum'],SomeClass::ROUND));
            $activeSheet->setCellValue($column++ . $row, round($item['sale_avg_price_for_unit'],SomeClass::ROUND));
            $activeSheet->setCellValue($column++ . $row, $item['sale_quantity']);
            $activeSheet->setCellValue($column++ . $row, round($item['sale_sum'],SomeClass::ROUND));
            $activeSheet->setCellValue($column . $row, $item['rests_quantity']);
        }

        $suppliers = (new Query())
            ->select('id, name')
            ->from(SomeClass::tableNameWithSchema())
            ->where(['id' => array_keys($suppIdAndColumn)])
            ->all();

        foreach ($suppliers as $supplier) {
            $activeSheet->setCellValue($suppIdAndColumn[$supplier['id']] . '2', $supplier['name']);
        }

        $lastColumn = $activeSheet->getHighestColumn();

        $activeSheet->setCellValue('D1', array_shift($firstListData)['point_count'] ?? null);
        $activeSheet->setAutoFilter('A2:' . $lastColumn . '2');

        $activeSheet->freezePane('A3');
    }

    /**
     * @return void
     * @throws Exception
     * @throws BadRequestHttpException
     * @throws \yii\db\Exception
     */
    private function createSecondSheet()
    {
        $this->objPHPExcel->createSheet(1);
        $this->objPHPExcel->setActiveSheetIndex(1);

        $activeSheet = $this->objPHPExcel->getActiveSheet();

        $activeSheet->setTitle('Name');

        $activeSheet->getStyle('A:M')
            ->applyFromArray(
                [
                    'font'      =>
                        [
                            'name' => 'Arial',
                            'size' => 10,
                        ],
                    'alignment' =>
                        [
                            'wrapText'   => true,
                            'horizontal' => Alignment::HORIZONTAL_LEFT,
                            'vertical'   => Alignment::VERTICAL_BOTTOM,
                        ],
                ]
            );

        $activeSheet->getStyle('A1:M2')
            ->applyFromArray(['font' => ['bold' => true]]);
        $activeSheet->getStyle('A3:F2')
            ->applyFromArray(['font' => ['bold' => true]]);

        $activeSheet->getStyle('A1:M2')
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFD9D9D9');
        $activeSheet->getStyle('A2:I2')
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFD9D9D9');
        $activeSheet->getStyle('J2:M2')
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFEFEFEF');
        $activeSheet->getStyle('A3:E3')
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFD9D9D9');

        $activeSheet->getStyle('A1:M3')
            ->applyFromArray(
                [
                    'borders' =>
                        [
                            'allBorders' =>
                                [
                                    'borderStyle' => Border::BORDER_THIN,
                                    'color'       => [
                                        'rgb' => '000000'
                                    ]
                                ],
                        ],
                ]
            );

        $activeSheet->getStyle('A3:E3')
            ->applyFromArray(
                [
                    'borders' =>
                        [
                            'top' =>
                                [
                                    'borderStyle' => Border::BORDER_THICK,
                                    'color'       => [
                                        'rgb' => '000000'
                                    ]
                                ],
                        ]
                ]
            );

        $activeSheet->getStyle('A3:I3')
            ->applyFromArray(
                [
                    'borders' =>
                        [
                            'bottom' =>
                                [
                                    'borderStyle' => Border::BORDER_THICK,
                                    'color'       => [
                                        'rgb' => '000000'
                                    ]
                                ],
                        ]
                ]
            );

        $activeSheet->getStyle('J1:M3')
            ->applyFromArray(
                [
                    'borders' =>
                        [
                            'outline' =>
                                [
                                    'borderStyle' => Border::BORDER_THICK,
                                    'color'       => [
                                        'rgb' => '000000'
                                    ]
                                ],
                        ]
                ]
            );

        $activeSheet->getRowDimension('2')
            ->setRowHeight(40);
        $activeSheet->getColumnDimension('A')
            ->setWidth(24);
        $activeSheet->getColumnDimension('B')
            ->setWidth(30);

        foreach (range('C', 'M') as $columnID) {
            $activeSheet->getColumnDimension($columnID)
                ->setWidth(16);
        }

        $activeSheet->mergeCells('D1:F1');
        $activeSheet->setCellValue('D1', 'Name');
        $activeSheet->getStyle('D1')
            ->applyFromArray(['font' => ['size' => 12]]);
        $activeSheet->mergeCells('J1:K1');
        $activeSheet->setCellValue('J1', 'Name');
        $activeSheet->mergeCells('L1:M1');
        $activeSheet->setCellValue('A2', 'Name');
        $activeSheet->setCellValue('B2', 'Name');
        $activeSheet->setCellValue('C2', 'Name');
        $activeSheet->setCellValue('D2', 'Name');
        $activeSheet->setCellValue('E2', 'Name');
        $activeSheet->setCellValue('F2', 'Name');
        $activeSheet->setCellValue('G2', 'Name');
        $activeSheet->setCellValue('H2', 'Name');
        $activeSheet->setCellValue('J2', 'Name');
        $activeSheet->setCellValue('K2', 'Name');
        $activeSheet->setCellValue('L2', 'Name');
        $activeSheet->setCellValue('M2', 'Name');
        $activeSheet->mergeCells('A3:E3');
        $activeSheet->setCellValue('A3', 'Итог');
        $activeSheet->getStyle('A3')
            ->applyFromArray(['font' => ['size' => 14]]);
        $activeSheet->getStyle('F3')
            ->applyFromArray(['font' => ['size' => 12]]);

        $secondListData = SomeClass::trendQuery($this->request);
        $i = 3;

        $headerValues = [
            'summF3'           => 0,
            'percentG3'        => 0,
            'summProjectedH3'  => 0,
            'lossI3'           => 0,
            'countEffectiveJ3' => 0,
            'summEffectiveK3'  => 0,
            'countLossL3'      => 0,
            'summLossM3'       => 0,
        ];

        foreach ($secondListData->queryAll() as $item) {
            $i++;
            $activeSheet->getRowDimension($i)
                ->setRowHeight(20);
            $activeSheet->getStyle('A' . $i . ':I' . $i)
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);

            $activeSheet->setCellValue('A' . $i, $item['name_1']);
            $activeSheet->setCellValue('B' . $i, $item['name_2']);
            $activeSheet->setCellValue('C' . $i, round($item['price'],SomeClass::ROUND));
            $activeSheet->setCellValue('D' . $i, round($item['avg_price'],SomeClass::ROUND));
            $activeSheet->setCellValue('E' . $i, $item['inc_quantity']);
            $activeSheet->setCellValue('F' . $i, round($item['inc_sum'],SomeClass::ROUND));
            $activeSheet->setCellValue('G' . $i, $item['percent'] . '%');
            $activeSheet->setCellValue('H' . $i, round($item['projected_sum'],SomeClass::ROUND));
            $activeSheet->setCellValue('I' . $i, round($item['loss_sum'],SomeClass::ROUND));

            $headerValues['summF3'] += $item['inc_sum'];
            $headerValues['percentG3'] += $item['percent'];
            $headerValues['summProjectedH3'] += $item['projected_sum'];
            $headerValues['lossI3'] += $item['loss_sum'];

            $lossColor = $activeSheet->getStyle('I' . $i)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor();

            if ($item['is_effective']) {
                $lossColor->setARGB('FFC6EFCE');
                $headerValues['countEffectiveJ3']++;
                $headerValues['summEffectiveK3'] += $item['loss_sum'];
            } else {
                $lossColor->setARGB('FFFFC7CE');
                $headerValues['countLossL3']++;
                $headerValues['summLossM3'] += $item['loss_sum'];
            }
        }

        $activeSheet->setCellValue('F3', round($headerValues['summF3'],SomeClass::ROUND));
        $activeSheet->setCellValue('G3', $headerValues['percentG3']);
        $activeSheet->setCellValue('H3', round($headerValues['summProjectedH3'],SomeClass::ROUND));
        $activeSheet->setCellValue('I3', round($headerValues['lossI3'],SomeClass::ROUND));
        $activeSheet->setCellValue('J3', $headerValues['countEffectiveJ3']);
        $activeSheet->setCellValue('K3', round($headerValues['summEffectiveK3'],SomeClass::ROUND));
        $activeSheet->setCellValue('L3', $headerValues['countLossL3']);
        $activeSheet->setCellValue('M3', round($headerValues['summLossM3'],SomeClass::ROUND));

        if ($headerValues['summLossM3'] + $headerValues['summEffectiveK3'] < 0) {
            $activeSheet->setCellValue('I2', 'Потеря');
        } else {
            $activeSheet->setCellValue('I2', 'Экономия');
        }
    }

    /**
     * @throws Exception
     * @throws BadRequestHttpException|\yii\db\Exception
     */
    public function createThirdSheet(): void
    {
        $this->objPHPExcel->createSheet(2);
        $this->objPHPExcel->setActiveSheetIndex(2);

        $activeSheet = $this->objPHPExcel->getActiveSheet();

        $activeSheet->setTitle('Name');

        $activeSheet->getStyle('A:H')
            ->applyFromArray(
                [
                    'font'      =>
                        [
                            'name' => 'Arial',
                            'size' => 10,
                        ],
                    'alignment' =>
                        [
                            'wrapText' => true,
                        ]
                ]
            );

        $activeSheet->getStyle('A1:H2')
            ->applyFromArray(
                [
                    'borders' =>
                        [
                            'allBorders' =>
                                [
                                    'borderStyle' => Border::BORDER_THIN,
                                    'color'       => [
                                        'rgb' => '000000'
                                    ]
                                ],
                        ]
                ]
            );

        $activeSheet->getStyle('A1:H2')
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFD9D9D9');

        $activeSheet->getRowDimension('2')
            ->setRowHeight(40);

        $activeSheet->getStyle('A1:H2')
            ->applyFromArray(
                [
                    'alignment' =>
                        [
                            'horizontal' => Alignment::HORIZONTAL_LEFT,
                            'vertical'   => Alignment::VERTICAL_BOTTOM,
                        ],
                ]
            );

        $activeSheet->mergeCells('A1:A2');
        $activeSheet->mergeCells('B1:B2');
        $activeSheet->mergeCells('C1:C2');
        $activeSheet->mergeCells('D1:E1');
        $activeSheet->mergeCells('F1:G1');
        $activeSheet->mergeCells('H1:H2');

        $activeSheet->getColumnDimension('A')
            ->setWidth(25);
        $activeSheet->getColumnDimension('B')
            ->setWidth(35);
        $activeSheet->getColumnDimension('C')
            ->setWidth(25);

        foreach (range('D', 'H') as $columnID) {
            $activeSheet->getColumnDimension($columnID)
                ->setWidth(12);
        }

        $activeSheet->getStyle('D1:H2')
            ->applyFromArray(['font' => ['bold' => true]]);
        $activeSheet->getStyle('A1:C2')
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(Border::BORDER_THICK);
        $activeSheet->getStyle('D1:G2')
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(Border::BORDER_THICK);
        $activeSheet->getStyle('H1:H2')
            ->getBorders()
            ->getOutline()
            ->setBorderStyle(Border::BORDER_THICK);

        $activeSheet->setCellValue('A1', 'Name');
        $activeSheet->setCellValue('B1', 'Name');
        $activeSheet->setCellValue('C1', 'Name');
        $activeSheet->setCellValue('D1', 'Name');
        $activeSheet->setCellValue('D2', 'Name');
        $activeSheet->setCellValue('E2', 'Name');
        $activeSheet->setCellValue('F1', 'Name');
        $activeSheet->setCellValue('F2', 'Name');
        $activeSheet->setCellValue('G2', 'Name');
        $activeSheet->setCellValue('H1', 'Name');

        $this->thirdListData = SomeClass::summaryQuery($this->request)->queryAll();

        $i = 2;

        foreach ($this->thirdListData as $item) {
            $i++;
            $activeSheet->getRowDimension($i)
                ->setRowHeight(40);
            $activeSheet->getStyle('A' . $i . ':G' . $i)
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);

            $activeSheet->setCellValue('A' . $i, $item['name']);
            $activeSheet->setCellValue('B' . $i, $item['address']);
            $activeSheet->setCellValue('C' . $i, $item['store']);
            $activeSheet->setCellValue('D' . $i, $item['effective_count']);
            $activeSheet->setCellValue('E' . $i, round($item['effective_sum'],SomeClass::ROUND));
            $activeSheet->setCellValue('F' . $i, $item['loss_count']);
            $activeSheet->setCellValue('G' . $i, round($item['loss_sum'],SomeClass::ROUND));
            $activeSheet->setCellValue('H' . $i, round($item['total_sum'],SomeClass::ROUND));
        }
    }
}
