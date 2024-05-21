<?php

namespace craft\cloud;

use Craft;
use craft\cache\DbCache;
use craft\cloud\fs\BuildArtifactsFs;
use craft\cloud\Helper as CloudHelper;
use craft\cloud\queue\SqsQueue;
use craft\cloud\runtime\event\CliHandler;
use craft\cloud\web\AssetManager;
use craft\db\Table;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\log\MonologTarget;
use craft\queue\Queue as CraftQueue;
use GuzzleHttp\Psr7\Request;
use HttpSignatures\Context;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use yii\base\Exception;
use yii\web\DbSession;

class Helper
{
    public static function isCraftCloud(): bool
    {
        return App::env('CRAFT_CLOUD') ?? App::env('AWS_LAMBDA_RUNTIME_API') ?? false;
    }

    public static function artifactUrl(string $path = ''): string
    {
        return (new BuildArtifactsFs())->createUrl($path);
    }

    public static function setMemoryLimit(int|string $limit, int|string $offset = 0): int|float
    {
        $memoryLimit = ConfigHelper::sizeInBytes($limit) - ConfigHelper::sizeInBytes($offset);
        Craft::$app->getConfig()->getGeneral()->phpMaxMemoryLimit((string) $memoryLimit);
        Craft::info("phpMaxMemoryLimit set to $memoryLimit");

        return $memoryLimit;
    }

    public static function removeAttributeFromRule(array $rule, string $attributeToRemove): ?array
    {
        $attributes = Collection::wrap($rule[0])
            ->reject(fn($attribute) => $attribute === $attributeToRemove);

        // We may end up with a rule with an empty array of attributes.
        // We still need to keep that rule around so any potential
        // scenarios get defined from the 'on' key.
        $rule[0] = $attributes->all();

        return $rule;
    }

    public static function removeAttributeFromRules(array $rules, string $attributeToRemove): array
    {
        return Collection::make($rules)
            ->map(fn($rule) => Helper::removeAttributeFromRule($rule, $attributeToRemove))
            ->all();
    }

    /**
     * A version of tableExists that doesn't rely on the cache component
     */
    public static function tableExists(string $table, ?string $schema = null): bool
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

    public static function modifyConfig(array &$config, string $appType): void
    {
        if (!CloudHelper::isCraftCloud()) {
            return;
        }

        // Make sure the app has an ID and it isn't the default
        if (!$config['id'] || $config['id'] === 'CraftCMS') {
            $projectId = App::env('CRAFT_CLOUD_PROJECT_ID');
            $config['id'] = "CraftCMS--$projectId";
        }

        if ($appType === 'web') {
            $config['components']['session'] = function() {
                $config = App::sessionConfig();

                if (static::tableExists(Table::PHPSESSIONS)) {
                    $config['class'] = DbSession::class;
                    $config['sessionTable'] = Table::PHPSESSIONS;
                }

                return Craft::createObject($config);
            };
        }

        $config['components']['cache'] = function() {
            $config = static::tableExists(Table::CACHE) ? [
                'class' => DbCache::class,
                'cacheTable' => Table::CACHE,
                'defaultDuration' => Craft::$app->getConfig()->getGeneral()->cacheDuration,
            ] : App::cacheConfig();

            return Craft::createObject($config);
        };

        $config['components']['queue'] = function() {
            return Craft::createObject([
                'class' => CraftQueue::class,
                'ttr' => CliHandler::maxExecutionSeconds(),
                'proxyQueue' => Module::getInstance()->getConfig()->useQueue ? [
                    'class' => SqsQueue::class,
                    'ttr' => CliHandler::maxExecutionSeconds(),
                    'url' => Module::getInstance()->getConfig()->sqsUrl,
                    'region' => Module::getInstance()->getConfig()->getRegion(),
                ] : null,
            ]);
        };

        $config['components']['assetManager'] = function() {
            $config = [
                'class' => AssetManager::class,
            ] + App::assetManagerConfig();

            return Craft::createObject($config);
        };

        $config['container']['definitions'] = [
            MonologTarget::class => function($container, $params, $config) {
                return new MonologTarget([
                    'logContext' => false,
                ] + $config);
            },
        ];
    }

    public static function createSigningContext(iterable $headers = []): Context
    {
        $headers = Collection::make($headers);

        return new Context([
            'keys' => [
                'hmac' => Module::getInstance()->getConfig()->signingKey,
            ],
            'algorithm' => 'hmac-sha256',
            'headers' => $headers->all(),
        ]);
    }

    public static function base64UrlEncode(string $data): string
    {
        $base64Url = strtr(base64_encode($data), '+/', '-_');

        return rtrim($base64Url, '=');
    }

    public static function validateProjectTypeForEdition(string $projectType, string $edition): bool
    {
        // TODO: replace with enums
        if (!$edition || ($projectType === 'team' && $edition === 'pro')) {
            return false;
        }

        return true;
    }

    public static function makeGatewayApiRequest(iterable $headers): ResponseInterface
    {
        if (!Helper::isCraftCloud()) {
            throw new Exception('Gateway API requests are only supported in a Craft Cloud environment.');
        }

        $headers = Collection::make($headers)
            ->put(HeaderEnum::REQUEST_TYPE->value, 'api');

        if (Module::getInstance()->getConfig()->getDevMode()) {
            $headers->put(HeaderEnum::DEV_MODE->value, '1');
        }

        $url = Craft::$app->getRequest()->getIsConsoleRequest()
            ? Module::getInstance()->getConfig()->getPreviewDomainUrl()
            : Craft::$app->getRequest()->getHostInfo();

        if (!$url) {
            throw new Exception('Gateway API requests require a URL.');
        }

        $context = Helper::createSigningContext($headers->keys());
        $request = new Request(
            'HEAD',
            (string) $url,
            $headers->all(),
        );

        return Craft::createGuzzleClient()->send(
            $context->signer()->sign($request)
        );
    }
}
