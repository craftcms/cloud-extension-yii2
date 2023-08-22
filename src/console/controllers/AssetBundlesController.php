<?php

namespace craft\cloud\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Throwable;
use yii\console\Exception;
use yii\console\ExitCode;
use yii\web\AssetBundle;

class AssetBundlesController extends Controller
{
    public function actionPublishBundle(string $className): int
    {
        /** @var AssetBundle $assetBundle */
        try {
            $assetBundle = Craft::createObject($className);

            if (!is_subclass_of($className, AssetBundle::class)) {
                throw new Exception();
            }
        } catch(Throwable $e) {
            $this->stderr(sprintf(
                'Skipping “%s”',
                $className,
                AssetBundle::class,
            ), Console::FG_RED);
            $this->stderr(PHP_EOL);

            return ExitCode::USAGE;
        }

        if (!is_subclass_of($className, AssetBundle::class)) {
            $this->stderr(sprintf('“%s” must be a subclass of “%s”.', $className, AssetBundle::class), Console::FG_RED);
            return ExitCode::USAGE;
        }

        $assetManager = Craft::$app->getAssetManager();
        $assetBundle->publish($assetManager);

        $this->stdout("Published “{$className}”");
        $this->stdout(PHP_EOL);

        return ExitCode::OK;
    }

    public function actionPublish(): int
    {
        $classMap = require(Craft::getAlias('@vendor/composer/autoload_classmap.php'));


        $this->stdout('Publishing asset bundles ...');
        $this->stdout(PHP_EOL);

        // TODO: run in parallel
        Collection::make($classMap)
            ->keys()
            ->filter(function ($className): bool|int {
                // TODO: event
                return preg_match('/(\\\assets\\\|assetbundles?|Asset(Bundle)?$)/', $className);
            })
            ->mapWithKeys(function(string $className) {
                $process = new Process([
                    PHP_BINARY,
                    $this->request->getScriptFile(),
                    'cloud/asset-bundles/publish-bundle',
                    $className,
                    '2>&1',
                ]);

                $process->run();

                $output = $process->isSuccessful()
                    ? $process->getOutput()
                    : $process->getErrorOutput();

                $this->stdout("    - $output");

                return [$className => $process];
            });

        $this->stdout('Done publishing asset bundles.');
        $this->stdout(PHP_EOL);

        return ExitCode::OK;
    }
}
