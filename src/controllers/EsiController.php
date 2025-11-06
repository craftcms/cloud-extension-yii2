<?php

namespace craft\cloud\controllers;

use Craft;
use craft\cloud\Module;
use yii\web\Response;

class EsiController extends \craft\web\Controller
{
    public function beforeAction($action): bool
    {
        return Module::getInstance()
            ->getUrlSigner()
            ->verify(Craft::$app->getRequest()->getAbsoluteUrl());
    }

    public function actionRenderTemplate(string $template, array $variables = []): Response
    {
        return $this->renderTemplate($template, $variables);
    }
}
