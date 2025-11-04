<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\cloud\controllers;

use Craft;

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
     * TODO: verify signature: https://github.com/macgirvin/HTTP-Message-Signer
     */
    private function verifySignature(): bool
    {
        $signature = Craft::$app->getRequest()->getQueryParam('signature');

        return $signature === 'TODO';
    }
}
