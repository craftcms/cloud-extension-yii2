<?php

namespace craft\cloud\controllers;

use Craft;
use craft\cloud\Module;
use yii\web\Response;

class EsiController extends \craft\web\Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        // TODO: enable verification
        return true;

        if (!parent::beforeAction($action)) {
            return false;
        }

        return Module::getInstance()
            ->getUrlSigner()
            ->verify(Craft::$app->getRequest()->getAbsoluteUrl());
    }

    public function actionRenderTemplate(string $template, array $variables = []): Response
    {
        return $this->renderTemplate($template, $variables);
    }
}
