<?php

namespace craft\cloud\console\controllers;

use Craft;
use craft\cloud\Module;
use craft\console\Controller;
use yii\console\ExitCode;

class InfoController extends Controller
{
    public function actionIndex(): int
    {
        $packageName = 'craftcms/cloud';
        $packageVersion = \Composer\InstalledVersions::getVersion($packageName);

        $this->table([
            'Extension',
            'Environment ID',
            'Build ID',
        ], [
            [
                "$packageName:$packageVersion",
                Module::getInstance()->getConfig()->environmentId,
                Craft::$app->getConfig()->getGeneral()->buildId,
            ],
        ]);
        return ExitCode::OK;
    }
}
