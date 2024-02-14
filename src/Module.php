<?php

namespace craft\cloud;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\cloud\fs\AssetsFs;
use craft\cloud\fs\StorageFs;
use craft\cloud\fs\TmpFs;
use craft\cloud\web\assets\uploader\UploaderAsset;
use craft\cloud\web\ResponseBehavior;
use craft\console\Application as ConsoleApplication;
use craft\debug\Module as DebugModule;
use craft\elements\Asset;
use craft\events\DefineRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\fs\Temp;
use craft\helpers\App;
use craft\imagetransforms\FallbackTransformer;
use craft\imagetransforms\ImageTransformer as CraftImageTransformer;
use craft\log\Dispatcher;
use craft\services\Elements;
use craft\services\Fs as FsService;
use craft\services\ImageTransforms;
use craft\utilities\ClearCaches;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use Illuminate\Support\Collection;
use yii\base\InvalidConfigException;
use yii\log\Target;

/**
 * @property-read Config $config
 * @property ?string $id When auto-bootstrapped as an extension, this can be `null`.
 */
class Module extends \yii\base\Module implements \yii\base\BootstrapInterface
{
    private Config $_config;

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        $this->id = $this->id ?? 'cloud';

        // Set instance early so our dependencies can use it
        self::setInstance($this);

        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'craft\\cloud\\cli\\controllers'
            : 'craft\\cloud\\controllers';

        $this->registerGlobalEventHandlers();
        $this->validateConfig();
    }

    /**
     * @inheritDoc
     */
    public function bootstrap($app): void
    {
        /** @var WebApplication|ConsoleApplication $app */

        // Required for controllers to be found
        $app->setModule($this->id, $this);

        $app->getView()->registerTwigExtension(new TwigExtension());

        Craft::setAlias('@artifactBaseUrl', Helper::artifactUrl());

        if (Helper::isCraftCloud()) {
            $this->bootstrapCloud($app);
        }

        if ($this->getConfig()->getUseAssetCdn()) {
            $app->getImages()->supportedImageFormats = ImageTransformer::SUPPORTED_IMAGE_FORMATS;

            /**
             * Currently this is the only reasonable way to change the default transformer
             */
            Craft::$container->set(
                CraftImageTransformer::class,
                ImageTransformer::class,
            );

            // TODO: this is to ensure PHP never transforms. Test this.
            Craft::$container->set(
                FallbackTransformer::class,
                ImageTransformer::class,
            );
        }
    }

    public function getConfig(): Config
    {
        if (isset($this->_config)) {
            return $this->_config;
        }

        $fileConfig = Craft::$app->getConfig()->getConfigFromFile($this->id);

        /** @var Config $config */
        $config = is_array($fileConfig)
            ? Craft::createObject(['class' => Config::class] + $fileConfig)
            : $fileConfig;

        $this->_config = Craft::configure($config, App::envConfig(Config::class, 'CRAFT_CLOUD_'));

        return $this->_config;
    }

    protected function bootstrapCloud(ConsoleApplication|WebApplication $app): void
    {
        // Set Craft memory limit to just below PHP's limit
        Helper::setMemoryLimit(ini_get('memory_limit'), $app->getErrorHandler()->memoryReserveSize);

        if ($app instanceof WebApplication) {
            Craft::setAlias('@web', $app->getRequest()->getHostInfo());

            $app->getResponse()->attachBehavior(
                'cloud',
                ResponseBehavior::class,
            );

            $app->getRequest()->secureHeaders = Collection::make($app->getRequest()->secureHeaders)
                ->reject(fn(string $header) => $header === 'X-Forwarded-Host')
                ->all();

            if ($app->getRequest()->getIsCpRequest()) {
                $app->getView()->registerAssetBundle(UploaderAsset::class);
            }
        }

        /** @var Dispatcher $dispatcher */
        $dispatcher = $app->getLog();
        $dispatcher->targets = Collection::make($dispatcher->getDefaultTargets())
            ->map(function(Target $target) {
                return Craft::configure($target, [
                    'logContext' => false,
                ]);
            })
            ->all();

        Craft::$container->set(
            Temp::class,
            TmpFs::class,
        );

        /**
         * We have to use DI here (can't use setModule), as
         * \craft\web\Application::debugBootstrap will be called after and override it.
         */
        Craft::$container->set(
            DebugModule::class,
            [
                'class' => DebugModule::class,
                'fs' => Craft::createObject(StorageFs::class),
                'dataPath' => 'debug',
            ],
        );

        $this->setComponents([
            'staticCaching' => StaticCaching::class,
        ]);

        $this->registerCloudEventHandlers();
    }

    protected function registerGlobalEventHandlers(): void
    {
        Event::on(
            ImageTransforms::class,
            ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = ImageTransformer::class;
            }
        );

        Event::on(
            FsService::class,
            FsService::EVENT_REGISTER_FILESYSTEM_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = AssetsFs::class;
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $e) {
                $e->roots[$this->id] = sprintf('%s/templates', $this->getBasePath());
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(\yii\base\Event $e) {
                /** @var CraftVariable $craftVariable */
                $craftVariable = $e->sender;
                $craftVariable->set('cloud', Module::class);
            }
        );
    }

    protected function registerCloudEventHandlers(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            [$this->get('staticCaching'), 'handleBeforeRenderPageTemplate'],
        );

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            [$this->get('staticCaching'), 'handleAfterRenderPageTemplate'],
        );

        Event::on(
            Elements::class,
            Elements::EVENT_INVALIDATE_CACHES,
            [$this->get('staticCaching'), 'handleInvalidateCaches'],
        );

        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            [$this->get('staticCaching'), 'handleRegisterCacheOptions'],
        );

        Event::on(
            Asset::class,
            Model::EVENT_DEFINE_RULES,
            function(DefineRulesEvent $e) {
                $e->rules = Helper::removeAttributeFromRules($e->rules, 'tempFilePath');
            }
        );
    }

    protected function validateConfig(): void
    {
        $config = $this->getConfig();

        if (!$config->validate()) {
            $firstErrors = $config->getFirstErrors();
            throw new InvalidConfigException(reset($firstErrors) ?: '');
        }
    }
}
