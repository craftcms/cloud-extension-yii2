<?php

namespace craft\cloud\cli\controllers;

use Craft;
use craft\cloud\Module;
use craft\console\Controller;
use craft\events\CancelableEvent;
use yii\console\ExitCode;

class UpController extends Controller
{
    public const EVENT_BEFORE_UP = 'beforeUp';
    public const EVENT_AFTER_UP = 'afterUp';

    public function actionIndex(): int
    {
        $event = new CancelableEvent();
        $this->trigger(self::EVENT_BEFORE_UP, $event);

        if (!$event->isValid) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->run('/setup/php-session-table');
        $this->run('/setup/db-cache-table');

        if (Craft::$app->getIsInstalled()) {
            $this->run('/up');
            Module::getInstance()->getStaticCache()->purgeGateway();
        }

        $event = new CancelableEvent();
        $this->trigger(self::EVENT_AFTER_UP, $event);

        if (!$event->isValid) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }
}
