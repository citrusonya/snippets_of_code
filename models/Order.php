<?php

namespace common\models;

use common\components\pgsql\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "external_order".
 *
 * @property int         $id
 * @property int|null    $order_id          ID заказа нашей системы
 * @property int|null    $service_id        ID внешней системы
 * @property string|null $order_guid        ID заказа внешней системы
 * @property string|null $status            Статус заказа
 * @property string|null $created_at        Дата создания
 * @property string|null $updated_at        Дата обновления
 * @property string|null $last_sync_at      Дата последней синхронизации
 * @property string|null $additional_fields Дополнительные данные
 * @property Class       $class
 */
class SomeClass extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'external_order';
    }

    public function rules(): array
    {
        return [
            [['order_id', 'service_id'], 'integer'],
            [['created_at', 'updated_at', 'last_sync_at', 'additional_fields'], 'safe'],
            [['order_guid', 'status'], 'string', 'max' => 255],
            [['order_id'], 'unique'],
            [
                ['order_id'],
                'exist',
                'skipOnError'     => true,
                'targetClass'     => SomeClass::classNameWithSchema(),
                'targetAttribute' => ['order_id' => 'id']
            ],
        ];
    }

    public function behaviors(): array
    {
        return ArrayHelper::merge(
            parent::behaviors(),
            [
                'timestamp' => [
                    'class'              => TimestampBehavior::class,
                    'createdAtAttribute' => 'created_at',
                    'updatedAtAttribute' => 'updated_at',
                    'value'              => \gmdate('Y-m-d H:i:s'),
                ],
            ]
        );
    }

    public function attributeLabels(): array
    {
        return [
            'id'                => 'ID',
            'order_id'          => 'ID заказа нашей системы',
            'service_id'        => 'ID внешней системы',
            'order_guid'        => 'ID заказа внешней системы',
            'status'            => 'Статус заказа',
            'created_at'        => 'Дата создания',
            'updated_at'        => 'Дата обновления',
            'last_sync_at'      => 'Дата последней синхронизации',
            'additional_fields' => 'Дополнительные данные',
        ];
    }

    /**
     * Gets query for [[Order]].
     *
     * @return ActiveQuery
     */
    public function getOrder(): ActiveQuery
    {
        return $this->hasOne(SomeClass::classNameWithSchema(), ['id' => 'order_id']);
    }
}
