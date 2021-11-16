<?php

namespace api_web\classes;

use api_web\components\SomeClass;
use api_web\components\SomeClass;
use api_web\services\SomeClass;
use common\models\SomeClass;
use common\models\SomeClass;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use yii\web\BadRequestHttpException;

/**
 * Class WebApi
 *
 * @property SomeService $service
 * @package api_web\classes
 */
class WebApi extends SomeClass
{
    /**
     * Проверяем корректность логина и пароля для доступа в ЛК METRO
     *
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws GuzzleException
     */
    public function checkAuth($request)
    {
        $orgId = $this->user->organization_id;
        $this->validateRequest($request, ['login' => 'Введите логин', 'password' => 'Введите пароль']);

        /**
         * Выбираем настройки
         */
        $settings = SomeClass::find()
            ->where(
                [
                    'name'       => [
                        SomeClass::PARAMETR_LOGIN,
                        SomeClass::PARAMETR_PASSWORD,
                        SomeClass::PARAMETR_IDAM
                    ],
                    'service_id' => SomeClass::SERVICE_ID,
                ]
            )
            ->indexBy('name')
            ->all();

        /**
         * Выбираем значения настроек конкретной организации
         */
        $getData = SomeClass::find()
            ->alias('isv')
            ->where(
                [
                    'isv.org_id'     => $orgId,
                    'isv.setting_id' => array_column($settings, 'id')
                ]
            )
            ->indexBy('setting_id')
            ->all();

        /**
         * Создаем логин и пароль, если их нет в БД
         * Или обновляем значения
         *
         * @var Setting $setting
         */
        foreach ($settings as $setting) {
            $organizationSettingValue = $getData[$setting->id] ?? null;

            if ($organizationSettingValue === null) {
                $organizationSettingValue = new SettingValue();
                $organizationSettingValue->setAttributes(
                    [
                        'org_id'     => $orgId,
                        'setting_id' => $setting->id,
                    ]
                );
            }

            $organizationSettingValue->value = $request[$setting->name] ?? null;
            $organizationSettingValue->json_value = null;
            $organizationSettingValue->saveOrThrow();
        }

        /**
         * Проверяем логин и пароль на корректность между системами
         */
        try {
            (new Service(['orgId' => $this->user->organization_id]))->login();
        } catch (Exception $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return true;
    }
}
