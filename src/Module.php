<?php
namespace craft\cloud;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\App;
use craft\services\Fs as FsService;
use craft\services\ImageTransforms;
use craft\web\View;
use League\Uri\Contracts\UriInterface;
use League\Uri\Uri;
use League\Uri\UriTemplate;
use yii\base\Event;

class Module extends \yii\base\Module implements \yii\base\BootstrapInterface
{
    public const REDIS_DATABASE_CACHE = 0;
    public const REDIS_DATABASE_SESSION = 1;
    public const REDIS_DATABASE_MUTEX = 2;
    public const MUTEX_EXPIRE_WEB = 30;
    public const MUTEX_EXPIRE_CONSOLE = 900;

    /**
     * @inheritDoc
     */
    public function bootstrap($app): void
    {
        $this->registerEventHandlers();

        $app->setAliases([
            '@craftCloudAssetBaseUrl' => self::getAssetUrl(),
            '@craftCloudBuildBaseUrl' => self::getBuildUrl(),
        ]);

        if ($this->isCraftCloud()) {
            $app->setComponents([
                'cache' => [
                    'class' => \yii\redis\Cache::class,
                    'redis' => self::getRedisConfig() + [
                        'database' => self::REDIS_DATABASE_CACHE
                    ],
                    'defaultDuration' => Craft::$app->getConfig()->getGeneral()->cacheDuration,
                ],
                'mutex' => [
                    'class' => \craft\mutex\Mutex::class,
                    'mutex' => [
                        'class' => \craft\cloud\Mutex::class,
                        'redis' => self::getRedisConfig() + [
                            'database' => self::REDIS_DATABASE_MUTEX
                        ],
                        'expire' => Craft::$app->getRequest()->getIsConsoleRequest()
                            ? self::MUTEX_EXPIRE_CONSOLE
                            : self::MUTEX_EXPIRE_WEB,
                    ],
                ],
            ]);

            if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
                $app->setComponents([
                    'session' => [
                        'class' => \yii\redis\Session::class,
                        'redis' => self::getRedisConfig() + [
                            'database' => self::REDIS_DATABASE_SESSION
                        ],
                    ] + App::sessionConfig(),
                ]);
            }
        }

        // TODO: https://github.com/craftcms/cloud/issues/155
        // $app->setComponents([
        //     'queue' => [
        //         'class' => \craft\queue\Queue::class,
        //         'proxyQueue' => [
        //             'class' => \yii\queue\sqs\Queue::class,
        //             'url' => App::env('CRAFT_CLOUD_SQS_URL'),
        //         ],
        //     ],
        // ]);

        // When the module is resolved, the module config is merged into the definition,
        // so we can't override anything set in \craft\web\Application::debugBootstrap
        // or config/debug.php
        if ($this->isCraftCloud()) {
            Craft::$container->setDefinitions([
                \craft\debug\Module::class => [
                    'class' => \craft\debug\Module::class,
                    'fs' => Craft::createObject([
                        'class' => Fs::class,
                    ]),
                    'dataPath' => 'debug',
                ]
            ]);
        }
    }


    protected function isCraftCloud(): bool
    {
        // TODO: should this be a dedicated env var?
        return (bool) App::env('CRAFT_CLOUD_BUILD_ID');
    }

    protected static function getCdnUrl(string $path = ''): UriInterface
    {
        $template = new UriTemplate(
            self::collapseSlashes($path),
            [
                'envId' => App::env('CRAFT_CLOUD_ENVIRONMENT_ID') ?? '__ENVIRONMENT_ID__',
                'buildId' => App::env('CRAFT_CLOUD_BUILD_ID') ?? '__BUILD_ID__',
                'projectId' => App::env('CRAFT_CLOUD_PROJECT_ID') ?? '__PROJECT_ID__',
            ]
        );

        return Uri::createFromBaseUri(
            $template->expand(),
            App::env('CRAFT_CLOUD_CDN_BASE_URL') ?? 'https://cdn.craft.cloud',
        );
    }

    protected static function getAssetUrl(string $path = ''): UriInterface
    {
        return self::getCdnUrl("{envId}/assets/${path}");
    }

    protected static function getBuildUrl(string $path = ''): UriInterface
    {
        return self::getCdnUrl("{envId}/builds/{buildId}/${path}");
    }

    protected static function collapseSlashes(string $path): string
    {
        return preg_replace('#/{2,}#', '/', $path);
    }

    protected static function getRedisConfig(): array
    {
        $url = App::env('CRAFT_CLOUD_REDIS_URL') ?? 'tcp://localhost:6379';
        $urlParts = parse_url($url);

        return [
            'scheme' => $urlParts['scheme'],
            'hostname' => $urlParts['host'],
            'port' => $urlParts['port'],
        ];
    }

    protected function registerEventHandlers(): void
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
                $event->types[] = Fs::class;
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
}
