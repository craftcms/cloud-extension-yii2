<?php

namespace craft\cloud;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\cloud\fs\AssetsFs;
use craft\cloud\twig\TwigExtension;
use craft\cloud\web\assets\uploader\UploaderAsset;
use craft\cloud\web\ResponseEventHandler;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\events\DefineRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\imagetransforms\FallbackTransformer;
use craft\imagetransforms\ImageTransformer as CraftImageTransformer;
use craft\log\MonologTarget;
use craft\services\Fs as FsService;
use craft\services\ImageTransforms;
use craft\web\Application as WebApplication;
use craft\web\View;
use Illuminate\Support\Collection;
use Psr\Log\LogLevel;
use yii\base\InvalidConfigException;

/**
 * @property ?string $id When auto-bootstrapped as an extension, this can be `null`.
 */
class Module extends \yii\base\Module implements \yii\base\BootstrapInterface
{
    private Config $_config;

    /**
     * @throws InvalidConfigException
     * @param WebApplication|ConsoleApplication $app
     * @inheritDoc
     */
    public function bootstrap($app): void
    {
        $this->id = $this->id ?? 'cloud';

        // Set instance early so our dependencies can use it
        self::setInstance($this);

        $this->controllerNamespace = $app->getRequest()->getIsConsoleRequest()
            ? 'craft\\cloud\\cli\\controllers'
            : 'craft\\cloud\\controllers';

        $this->registerGlobalEventHandlers();
        $this->validateConfig();

        // Required for controllers to be found
        $app->setModule($this->id, $this);

        $app->getView()->registerTwigExtension(new TwigExtension());

        Craft::setAlias('@artifactBaseUrl', Helper::artifactUrl());

        if (Helper::isCraftCloud()) {
            $this->bootstrapCloud($app);
        }

        if ($this->getConfig()->useAssetCdn) {
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

            if ($app->getRequest()->getIsCpRequest()) {
                $app->getView()->registerAssetBundle(UploaderAsset::class);
            }
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
        $this->setMemoryLimit(
            ini_get('memory_limit'),
            $app->getErrorHandler()->memoryReserveSize,
        );

        $this->setComponents([
            'staticCache' => StaticCache::class,
        ]);

        $this->registerCloudEventHandlers();

        $app->getLog()->targets[] = Craft::createObject([
            'class' => MonologTarget::class,
            'name' => 'cloud',
            'level' => $this->getConfig()->logLevel ?? (App::devMode() ? LogLevel::INFO : LogLevel::WARNING),
            'categories' => ['craft\cloud\*'],
        ]);

        if ($app instanceof WebApplication) {
            Craft::setAlias('@web', $app->getRequest()->getHostInfo());

            $app->getRequest()->secureHeaders = Collection::make($app->getRequest()->secureHeaders)
                ->reject(fn(string $header) => $header === 'X-Forwarded-Host')
                ->all();

            // Important this gets called last so multi-value headers aren't prematurely joined
            (new ResponseEventHandler())->handle();
        }
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
    }

    protected function registerCloudEventHandlers(): void
    {
        $this->getStaticCache()->registerEventHandlers();

        Event::on(
            Asset::class,
            Model::EVENT_DEFINE_RULES,
            function(DefineRulesEvent $e) {
                $e->rules = $this->removeAttributeFromRules($e->rules, 'tempFilePath');
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

    public function getStaticCache(): StaticCache
    {
        return $this->get('staticCache');
    }

    private function removeAttributeFromRule(array $rule, string $attributeToRemove): array
    {
        $attributes = Collection::wrap($rule[0])
            ->reject(fn($attribute) => $attribute === $attributeToRemove);

        // We may end up with a rule with an empty array of attributes.
        // We still need to keep that rule around so any potential
        // scenarios get defined from the 'on' key.
        $rule[0] = $attributes->all();

        return $rule;
    }

    private function removeAttributeFromRules(array $rules, string $attributeToRemove): array
    {
        return Collection::make($rules)
            ->map(fn($rule) => $this->removeAttributeFromRule($rule, $attributeToRemove))
            ->all();
    }

    private function setMemoryLimit(int|string $limit, int|string $offset = 0): int|float
    {
        $memoryLimit = ConfigHelper::sizeInBytes($limit) - ConfigHelper::sizeInBytes($offset);
        Craft::$app->getConfig()->getGeneral()->phpMaxMemoryLimit((string) $memoryLimit);
        Craft::info("phpMaxMemoryLimit set to $memoryLimit", __METHOD__);

        return $memoryLimit;
    }
}
