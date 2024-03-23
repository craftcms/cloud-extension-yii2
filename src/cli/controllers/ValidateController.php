<?php

namespace craft\cloud\cli\controllers;

use Craft;
use craft\cloud\Helper;
use craft\console\Controller;
use yii\console\ExitCode;

class ValidateController extends Controller
{
    public function actionProjectType(string $projectType): int
    {
        $edition = Craft::$app->getProjectConfig()->get('system.edition', true);

        if (!$edition) {
            $this->stderr('Unable to determine the Craft CMS edition.');
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!Helper::validateProjectTypeForEdition($projectType, $edition)) {
            $this->stderr("The Craft CMS edition “{$edition}” is not allowed for Craft Cloud projects of type “{$projectType}”.");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }
}
