<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\cloud\controllers;

use Craft;
use craft\cloud\Module;
use craft\helpers\UrlHelper;

class TemplatesController extends \craft\controllers\TemplatesController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        return $this->verifySignature();
    }

    /**
     * TODO: verify signature with: https://github.com/macgirvin/HTTP-Message-Signer
     */
    private function verifySignature(): bool
    {
        $signature = Craft::$app->getRequest()->getQueryParam('signature');
        $url = UrlHelper::removeParam(Craft::$app->getRequest()->getAbsoluteUrl(), 'signature');

        return hash_equals(
            hash_hmac('sha256', $url, Module::getInstance()->getConfig()->signingKey),
            $signature,
        );
    }
}
