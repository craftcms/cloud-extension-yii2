<?php

namespace craft\cloud\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Test controller
 */
class TestController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    public function actionFatal(): Response
    {
        /** @phpstan-ignore-next-line  */
        someFunction();

        return $this->asSuccess();
    }

    public function actionOom(): Response
    {
        $data = str_repeat('a', PHP_INT_MAX);
        return $this->asJson($data);
    }

    public function actionHeaderLimit(int $limit = 1, string $char = 'a', string $header = 'Foo'): Response
    {
        $value = str_repeat($char, $limit);
        Craft::$app->getResponse()->setNoCacheHeaders();
        Craft::$app->getResponse()->getHeaders()->set(
            $header,
            $value,
        );

        return $this->asJson([
            'limit' => $limit,
            'char' => $char,
        ]);
    }
}
