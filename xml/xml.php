<?php
/**
 * @var string $fsrarId
 * @var Egais  $waybill
 */

use common\models\egais\SomeClass;
use yii\db\Query;

$dateCreated = (new DateTime())->format('Y-m-d');

$version = $waybill->version;

/**
 * @var SomeClass $shipper Отправитель - мы
 */
$shipper = $waybill->shipper;

/**
 * Получатель - поставщик
 */
$consignee = $waybill->consignee;

$shipperEntityType = $shipper->additional_fields['entity_type_key'] ?? 'UL';
$shipperRegionCode = $shipper->additional_fields['address']['RegionCode'] ?? null;

$consigneeEntityType = $consignee->additional_fields['entity_type_key'] ?? 'UL';
$consigneeRegionCode = $consignee->additional_fields['address']['RegionCode'] ?? null;

$waybillName = $waybill::TYPE_RETURN_PREFIX . '-' . $waybill->id;

$transport = json_decode((string)$waybill->transport) ?? [];

/**
 * При парсинге xml файла, если нам пришел закрытый тег: <wb:TRAN_FORWARDER/>,
 * мы сохраняем его в БД как {"TRAN_FORWARDER": [], ...}
 *
 * При рендере xml файла, документ ожидает строку, но получает массив, в результате чего возникает ошибка "Array to string conversion"
 * Поэтому перед сеттингом данных, необходимо проверить, является ли он массивом и если тип Array - меняем [] на null
 */

foreach ($transport as $i => $item) {
    $transport->$i = is_array($item) ? null : $item;
}

$positions = SomeClass::preparePositionsForReturnWaybill($waybill);
?>
<?= '<?xml version="1.0" encoding="UTF-8"?>' ?>
<ns:Documents xmlns:ns="http://fsrar.ru/WEGAIS/WB_DOC_SINGLE_01" xmlns:ce="http://fsrar.ru/WEGAIS/CommonV3"
              xmlns:oref="http://fsrar.ru/WEGAIS/ClientRef_v2" xmlns:pref="http://fsrar.ru/WEGAIS/ProductRef_v2"
              xmlns:unqualified_element="http://fsrar.ru/WEGAIS/CommonEnum"
              xmlns:wb="http://fsrar.ru/WEGAIS/TTNSingle_v<?= $version ?>" xmlns:xs="http://www.w3.org/2001/XMLSchema"
              xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <ns:Owner>
        <ns:FSRAR_ID><?= $fsrarId ?></ns:FSRAR_ID>
    </ns:Owner>
    <ns:Document>
        <ns:WayBill_v<?= $version ?>>
            <wb:Identity><?= $waybillName ?></wb:Identity>
            <wb:Header>
                <wb:Type><?= SomeClass::DOC_TYPE ?></wb:Type>
                <wb:NUMBER><?= $waybillName ?></wb:NUMBER>
                <wb:Date><?= $dateCreated ?></wb:Date>
                <wb:ShippingDate><?= $dateCreated ?></wb:ShippingDate>
                <wb:Shipper>
                    <oref:<?= $shipperEntityType ?>>
                        <?= empty($shipper->inn) ? '' : "<oref:INN>{$shipper->inn}</oref:INN>" . PHP_EOL ?>
                        <?= empty($shipper->kpp) ? '' : "<oref:KPP>{$shipper->kpp}</oref:KPP>" . PHP_EOL ?>
                        <oref:ClientRegId><?= $shipper->additional_fields['ClientRegId'] ?? $shipper->outer_uid ?></oref:ClientRegId>
                        <oref:FullName><?= $shipper->additional_fields['FullName'] ?? $shipper->name ?></oref:FullName>
                        <oref:ShortName><?= $shipper->additional_fields['ShortName'] ?? $shipper->name ?></oref:ShortName>
                        <oref:address>
                            <oref:Country><?= $shipper->additional_fields['address']['Country'] ?? null ?></oref:Country>
                            <?= empty($shipperRegionCode) ? '' : "<oref:RegionCode>{$shipperRegionCode}</oref:RegionCode>" . PHP_EOL ?>
                            <oref:description><?= $shipper->additional_fields['address']['description'] ?? null ?></oref:description>
                        </oref:address>
                    </oref:<?= $shipperEntityType ?>>
                </wb:Shipper>
                <wb:Consignee>
                    <oref:<?= $consigneeEntityType ?>>
                        <?= empty($consignee->inn) ? '' : "<oref:INN>{$consignee->inn}</oref:INN>" . PHP_EOL ?>
                        <?= empty($consignee->kpp) ? '' : "<oref:KPP>{$consignee->kpp}</oref:KPP>" . PHP_EOL ?>
                        <oref:ClientRegId><?= $consignee->additional_fields['ClientRegId'] ?? $consignee->outer_uid ?></oref:ClientRegId>
                        <oref:FullName><?= $consignee->additional_fields['FullName'] ?? $consignee->name ?></oref:FullName>
                        <oref:ShortName><?= $consignee->additional_fields['ShortName'] ?? $consignee->name ?></oref:ShortName>
                        <oref:address>
                            <oref:Country><?= $consignee->additional_fields['address']['Country'] ?? null ?></oref:Country>
                            <?= empty($consigneeRegionCode) ? '' : "<oref:RegionCode>{$consigneeRegionCode}</oref:RegionCode>" . PHP_EOL ?>
                            <oref:description><?= $consignee->additional_fields['address']['description'] ?? null ?></oref:description>
                        </oref:address>
                    </oref:<?= $consigneeEntityType ?>>
                </wb:Consignee>
                <wb:Transport>
                    <wb:ChangeOwnership><?= $transport->ChangeOwnership ?? 'NotChange' ?></wb:ChangeOwnership>
                    <wb:TRAN_TYPE><?= $transport->TRAN_TYPE ?? '413' ?></wb:TRAN_TYPE>
                    <wb:TRAN_COMPANY><?=  $transport->TRAN_COMPANY ?? $shipper->name ?? null ?></wb:TRAN_COMPANY>
                    <wb:TRANSPORT_TYPE><?=  $transport->TRANSPORT_TYPE ?? 'car' ?></wb:TRANSPORT_TYPE>
                    <wb:TRANSPORT_REGNUMBER><?= $transport->TRANSPORT_REGNUMBER ?? null ?></wb:TRANSPORT_REGNUMBER>
                    <wb:TRAN_CUSTOMER><?= $transport->TRAN_CUSTOMER ?? $consignee->additional_fields['FullName'] ?? $consignee->name ?></wb:TRAN_CUSTOMER>
                    <wb:TRAN_DRIVER><?= $transport->TRAN_DRIVER ?? null ?></wb:TRAN_DRIVER>
                    <wb:TRAN_LOADPOINT><?= $transport->TRAN_LOADPOINT ?? $shipper->additional_fields['address']['description'] ?? null ?></wb:TRAN_LOADPOINT>
                    <wb:TRAN_UNLOADPOINT><?= $transport->TRAN_UNLOADPOINT ?? $consignee->additional_fields['address']['description'] ?? null ?></wb:TRAN_UNLOADPOINT>
                    <wb:TRAN_FORWARDER><?= $transport->TRAN_FORWARDER ?? null ?></wb:TRAN_FORWARDER>
                </wb:Transport>
            </wb:Header>
            <wb:Content>
                <?php
                $positionCounter = 0;

                foreach ($positions as $position) {
                    /** @var EgaisWaybillContent $iwc */
                    $iwc = $position['iwc'];

                    /** @var OuterAgent $producer */
                    $producer = $position['producer'];

                    $producerEntityType = $position['producerEntityType'];
                    $producerRegionCode = $position['producerRegionCode'];

                    $positionCounter++;
                    ?>
                    <wb:Position>
                        <wb:Identity><?= $positionCounter ?></wb:Identity>
                        <wb:Product>
                            <?= empty($iwc->type) ? '' : "<pref:Type>{$iwc->type}</pref:Type>" . PHP_EOL ?>
                            <pref:AlcCode><?= $iwc->alc_code ?></pref:AlcCode>
                            <?= mb_strlen($iwc->short_name) > 64 ? '' : "<pref:ShortName>{$iwc->short_name}</pref:ShortName>" . PHP_EOL ?>
                            <pref:FullName><?= $iwc->full_name ?></pref:FullName>
                            <?php if (!empty((float)$iwc->capacity)) { ?>
                                <pref:Capacity><?= $iwc->capacity ?></pref:Capacity>
                            <?php } ?>
                            <pref:AlcVolume><?= $iwc->alc_volume ?></pref:AlcVolume>
                            <pref:ProductVCode><?= $iwc->product_v_code ?></pref:ProductVCode>
                            <pref:UnitType><?= $iwc->unit_type ?></pref:UnitType>
                            <pref:Producer>
                                <oref:<?= $producerEntityType ?>>
                                    <?= empty($producer->inn) ? '' : "<oref:INN>{$producer->inn}</oref:INN>" . PHP_EOL ?>
                                    <?= empty($producer->kpp) ? '' : "<oref:KPP>{$producer->kpp}</oref:KPP>" . PHP_EOL ?>
                                    <oref:ClientRegId><?= $producer->outer_uid ?></oref:ClientRegId>
                                    <oref:FullName><?= $producer->additional_fields['FullName'] ?? $producer->name ?></oref:FullName>
                                    <oref:ShortName><?= $producer->additional_fields['ShortName'] ?? $producer->name ?></oref:ShortName>
                                    <oref:address>
                                        <oref:Country><?= $producer->additional_fields['address']['Country'] ?? null ?></oref:Country>
                                        <?= empty($producerRegionCode) ? '' : "<oref:RegionCode>{$producerRegionCode}</oref:RegionCode>" . PHP_EOL ?>
                                        <oref:description><?= $producer->additional_fields['address']['description'] ?? null ?></oref:description>
                                    </oref:address>
                                </oref:<?= $producerEntityType ?>>
                            </pref:Producer>
                        </wb:Product>
                        <wb:Quantity><?= $position['quantity'] ?></wb:Quantity>
                        <wb:Price><?= round($iwc->price, 2) ?></wb:Price>
                        <wb:Party>-</wb:Party>
                        <wb:FARegId><?= $position['form_a'] ?></wb:FARegId>
                        <wb:InformF2>
                            <ce:F2RegId><?= $position['form_b'] ?></ce:F2RegId>
                            <?php if (!empty($position['marks'] ?? null)) { ?>
                                <ce:MarkInfo>
                                    <ce:boxpos>
                                        <ce:amclist>
                                            <?php foreach ($position['marks'] as $amc) { ?>
                                                <ce:amc><?= $amc ?></ce:amc>
                                            <?php } ?>
                                        </ce:amclist>
                                    </ce:boxpos>
                                </ce:MarkInfo>
                            <?php } ?>
                        </wb:InformF2>
                    </wb:Position>
                <?php } ?>
            </wb:Content>
        </ns:WayBill_v<?= $version ?>>
    </ns:Document>
</ns:Documents>
