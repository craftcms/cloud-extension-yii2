<?php

namespace craft\cloud\cli\controllers;

use Craft;
use craft\console\Controller;
use Illuminate\Support\Collection;
use samdark\log\PsrMessage;
use yii\console\ExitCode;

class BuildController extends Controller
{
    public function actionIndex(string $json): int
    {
        $options = json_decode($json, true);
        $exitCode = ExitCode::OK;

        Collection::make([
            'cloud/validate/project-type',
            'cloud/asset-bundles/publish',
        ])->each(function(string $command) use ($options, &$exitCode) {
            $params = $options[$command] ?? [];
            $exitCode = $this->run("/$command", $params);

            if ($exitCode !== ExitCode::OK) {
                Craft::error(new PsrMessage('Command failed.', [
                    'command' => $command,
                    'params' => $params,
                ]));

                return false;
            }
        });

        return $exitCode;
    }
}
