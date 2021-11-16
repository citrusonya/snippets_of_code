<?php

use yii\db\Migration;

/**
 * Class m000000_000000_create_table_order
 */
class m000000_000000_create_table_order extends Migration
{
    private const TABLE_NAME = 'order';
    private const COLUMN_NAME = 'order_id';
    private const FK_NAME = 'fk_order_id';

    public function safeUp()
    {
        $this->createTable(
            self::TABLE_NAME, [
                                'id'                => $this->primaryKey(11),
                                'order_id'          => $this->integer(11)->notNull()->comment('ID заказа'),
                                'service_id'        => $this->integer(11)->notNull()->comment('ID внешней системы'),
                                'order_guid'        => $this->string(255)->notNull()->comment(
                                    'ID заказа во внешней системе'
                                ),
                                'status'            => $this->string(255)->null()->defaultValue(null)->comment(
                                    'Статус заказа во внешней системе'
                                ),
                                'created_at'        => $this->timestamp()->notNull()->comment('Дата создания'),
                                'updated_at'        => $this->timestamp()->notNull()->comment('Дата обновления'),
                                'last_sync_at'      => $this->timestamp()->notNull()->comment(
                                    'Дата последней синхронизации'
                                ),
                                'additional_fields' => $this->json()->comment('Дополнительные данные')
                            ]
        );

        $this->addCommentOnTable(self::TABLE_NAME, 'Таблица сведений о заказах');

        $this->createIndex('ui_order_id', self::TABLE_NAME, self::COLUMN_NAME, true);

        $this->addForeignKey(
            self::FK_NAME,
            self::TABLE_NAME,
            self::COLUMN_NAME,
            'order',
            'id',
            'RESTRICT'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey(self::FK_NAME, self::TABLE_NAME);
        $this->dropTable(self::TABLE_NAME);
    }
}
