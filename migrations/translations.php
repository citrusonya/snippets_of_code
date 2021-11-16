<?php

use console\helpers\BatchTranslations;
use yii\db\Migration;

/**
 * Class m000000_000000_add_translations
 */
class m000000_000000_add_translations extends Migration
{
    public $translations_ru = [
        'error.create.organization'=> 'Ошибка создания организации',
    ];

    public $translations_en = [
        'error.create.organization'=> 'Organization creation error',
    ];

    public function safeUp()
    {
        BatchTranslations::insertCategory('ru', 'api_web', $this->translations_ru);
        BatchTranslations::insertCategory('en', 'api_web', $this->translations_en);
    }

    public function safeDown()
    {
        BatchTranslations::deleteCategory('ru', 'api_web', $this->translations_ru);
        BatchTranslations::deleteCategory('en', 'api_web', $this->translations_en);
    }
}
