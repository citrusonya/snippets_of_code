<?php

namespace api_web\controllers;

use api_web\classes\WebApi;
use api_web\components\WebApiController;

/**
 * Class SomeController
 *
 * @property WebApi $classWebApi
 * @package api_web\controllers
 */
class SomeController extends WebApiController
{
    public $className = WebApi::class;

    /**
     * @SWG\Post(path="/class/check-auth",
     *     tags={"Metro"},
     *     summary="Аутентификация",
     *     description="Метод создает или перезаписывает логин и пароль от ЛК в настройках интеграции",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *         name="post",
     *         in="body",
     *         required=true,
     *         @SWG\Schema (
     *              @SWG\Property(property="user", ref="#/definitions/User"),
     *              @SWG\Property(
     *                  property="request",
     *                  default={
     *                       "login": "test",
     *                       "password": "test12345"
     *                  }
     *
     *              )
     *         )
     *     ),
     *     @SWG\Response(
     *         response = 200,
     *         description = "success",
     *         @SWG\Schema(ref="#/definitions/BooleanResultDefinition")
     *     ),
     *     @SWG\Response(
     *         response = 400,
     *         description = "BadRequestHttpException"
     *     )
     * )
     */
    public function actionCheckAuth()
    {
        $this->response = $this->classWebApi->checkAuth($this->request);
    }
}
