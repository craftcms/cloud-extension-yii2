<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\cloud\controllers;

use Craft;
use craft\cloud\Module;

class TemplatesController extends \craft\controllers\TemplatesController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        return Module::getInstance()
            ->getUrlSigner()
            ->verify(Craft::$app->getRequest()->getAbsoluteUrl());
    }
}
