<?php

namespace craft\cloud\console\controllers;

use craft\console\Controller;
use yii\console\ExitCode;

class SetupController extends Controller
{
    public function actionIndex(): int
    {
        // TODO: link to docs, etc
        $this->run('info/index');
        return ExitCode::OK;
    }
}
