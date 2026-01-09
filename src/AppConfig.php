<?php

namespace craft\cloud;

use Craft;
use craft\cache\DbCache;
use craft\cloud\fs\TmpFs;
use craft\cloud\Helper as CloudHelper;
use craft\cloud\queue\SqsQueue;
use craft\cloud\runtime\event\CliHandler;
use craft\cloud\web\AssetManager;
use craft\db\Table;
use craft\debug\Module as DebugModule;
use craft\fs\Temp;
use craft\helpers\App;
use craft\log\MonologTarget;
use craft\queue\Queue as CraftQueue;
use yii\redis\Cache;
use yii\web\DbSession;

class AppConfig
{
    public function __construct(
        private readonly array  $config,
        private readonly string $appType,
    ) {
    }

    public function getConfig(): array
    {
        if (!CloudHelper::isCraftCloud()) {
            return $this->config;
        }

        $config = $this->config;
        $config['id'] = $this->getId();

        if ($this->appType === 'web') {
            $config['components']['session'] = $this->getSession();
        }

        $config['components']['cache'] = $this->getCache();
        $config['components']['queue'] = $this->getQueue();
        $config['components']['assetManager'] = $this->getAssetManager();
        $config['container']['definitions'] = $this->getDefinitions();

        return $config;
    }

    private function getSession(): \Closure
    {
        return function() {
            $config = App::sessionConfig();

            if ($this->tableExists(Table::PHPSESSIONS)) {
                $config['class'] = DbSession::class;
                $config['sessionTable'] = Table::PHPSESSIONS;
            }

            return Craft::createObject($config);
        };
    }

    private function getCache(): \Closure
    {
        return function() {
            $defaultDuration = Craft::$app->getConfig()->getGeneral()->cacheDuration;

            $redisSrv = App::env('CRAFT_CLOUD_REDIS_SRV');

            // Temporary for testing it out
            if ($redisSrv) {
                $record = dns_get_record($redisSrv, DNS_SRV);

                $url = 'redis://' . $record[0]['target'] . ':' . $record[0]['port'];

                return Craft::createObject([
                    'class' => Cache::class,
                    'defaultDuration' => $defaultDuration,
                    'redis' => [
                        'class' => Redis::class,
                        'url' => $url,
                        'database' => 0,
                    ],
                ]);
            }

            $redisUrl = App::env('CRAFT_CLOUD_REDIS_URL');

            if ($redisUrl) {
                return Craft::createObject([
                    'class' => Cache::class,
                    'defaultDuration' => $defaultDuration,
                    'redis' => [
                        'class' => Redis::class,
                        'url' => $redisUrl,
                        'database' => 0,
                    ],
                ]);
            }

            if ($this->tableExists(Table::CACHE)) {
                return Craft::createObject([
                    'class' => DbCache::class,
                    'cacheTable' => Table::CACHE,
                    'defaultDuration' => $defaultDuration,
                ]);
            }

            return Craft::createObject(App::cacheConfig());
        };
    }

    private function getQueue(): \Closure
    {
        return function() {
            return Craft::createObject([
                'class' => CraftQueue::class,
                'ttr' => CliHandler::MAX_EXECUTION_SECONDS,
                'proxyQueue' => Module::getInstance()->getConfig()->useQueue ? [
                    'class' => SqsQueue::class,
                    'ttr' => CliHandler::MAX_EXECUTION_SECONDS,
                    'url' => Module::getInstance()->getConfig()->sqsUrl,
                    'region' => Module::getInstance()->getConfig()->getRegion(),
                ] : null,
            ]);
        };
    }

    private function getAssetManager(): \Closure
    {
        return function() {
            $config = [
                'class' => AssetManager::class,
            ] + App::assetManagerConfig();

            return Craft::createObject($config);
        };
    }

    private function getId(): string
    {
        $id = $this->config['id'] ?? null;

        // Make sure the app has an ID and it isn't the default
        if (!$id || $id === 'CraftCMS') {
            $projectId = App::env('CRAFT_CLOUD_PROJECT_ID');

            return "CraftCMS--$projectId";
        }

        return $id;
    }

    private function getDefinitions(): array
    {
        return [
            Temp::class => TmpFs::class,

            MonologTarget::class => function($container, $params, $config) {
                return new MonologTarget([
                    'logContext' => false,
                ] + $config);
            },

            /**
             * We have to use DI (can't use setModule), as
             * \craft\web\Application::debugBootstrap will be called after and override it.
             */
            DebugModule::class => [
                'class' => DebugModule::class,
                'fs' => Craft::createObject(\craft\cloud\fs\StorageFs::class),
                'dataPath' => 'debug',
            ],
        ];
    }

    /**
     * A version of tableExists that doesn't rely on the cache component
     */
    private function tableExists(string $table, ?string $schema = null): bool
    {
        $db = Craft::$app->getDb();
        $params = [
            ':tableName' => $db->getSchema()->getRawTableName($table),
        ];

        if ($db->getIsMysql()) {
            // based on yii\db\mysql\Schema::findTableName()
            $sql = <<<SQL
SHOW TABLES LIKE :tableName
SQL;
        } else {
            // based on yii\db\pgsql\Schema::findTableName()
            $sql = <<<SQL
SELECT c.relname
FROM pg_class c
INNER JOIN pg_namespace ns ON ns.oid = c.relnamespace
WHERE ns.nspname = :schemaName AND c.relkind IN ('r','v','m','f', 'p')
and c.relname = :tableName
SQL;
            $params[':schemaName'] = $schema ?? $db->getSchema()->defaultSchema;
        }

        return (bool)$db->createCommand($sql, $params)->queryScalar();
    }
}
