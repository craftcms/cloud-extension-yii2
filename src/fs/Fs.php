<?php

namespace craft\cloud\fs;

use Aws\Credentials\Credentials;
use Aws\Handler\GuzzleV6\GuzzleHandler;
use Aws\S3\S3Client;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\cloud\Module;
use craft\flysystem\base\FlysystemFs;
use craft\helpers\App;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use DateTime;
use Illuminate\Support\Collection;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;

/**
 *
 * @property-read string $bucketName
 * @property-read string $prefix
 * @property-read S3Client $client
 * @property-read ?string $settingsHtml
 */
class Fs extends FlysystemFs
{
    public ?string $subfolder = null;
    private ?string $_expires = null;
    protected string $type;
    private S3Client $_client;

    public const TAG_PRIVATE = 'private';

    /**
     * @inheritdoc
     */
    public function getRootUrl(): ?string
    {
        if (!$this->hasUrls) {
            return null;
        }

        return Module::getCdnUrl($this->getPrefix());
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
                'subfolder',
            ],
        ];

        return $behaviors;
    }

    public function settingsAttributes(): array
    {
        return array_merge(parent::settingsAttributes(), ['expires']);
    }

    public function getExpires(): ?string
    {
        return $this->_expires;
    }

    public function setExpires(null|string|array $expires): void
    {
        $this->_expires = is_array($expires) ? $this->normalizeExpires($expires): $expires;
    }

    public function normalizeExpires(array $expires): ?string
    {
        $amount = (int)$expires['amount'];
        $period = $expires['period'];

        if (!$amount || !$period) {
            return null;
        }

        return "$amount $period";
    }

    /**
     * @inheritDoc
     */
    protected function createAdapter(): FilesystemAdapter
    {
        return new AwsS3V3Adapter(
            client: $this->getClient(),
            bucket: $this->getBucketName(),
            prefix: $this->getPrefix(),
        );
    }

    /**
     * @inheritDoc
     */
    protected function invalidateCdnPath(string $path): bool
    {
        // TODO: cloudflare
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function addFileMetadataToConfig(array $config): array
    {
        if (!empty($this->expires) && DateTimeHelper::isValidIntervalString($this->expires)) {
            $expires = new DateTime();
            $now = new DateTime();
            $expires->modify('+' . $this->expires);
            $diff = (int)$expires->format('U') - (int)$now->format('U');
            $config['CacheControl'] = 'max-age=' . $diff;
        }

        if (!$this->hasUrls) {
            $config['Tagging'] = self::TAG_PRIVATE;
        }

        return parent::addFileMetadataToConfig($config);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('fsSettings', [
            'fs' => $this,
            'periods' => Assets::periodList(),
        ]);
    }

    public function getPrefix(): string
    {
        return Collection::make([
            Module::getEnvironmentId(),
            $this->type,
            $this->subfolder,
        ])->filter()->join('/');
    }

    public function getBucketName(): string
    {
        return Craft::$app->id;
    }

    public function createCredentials(): ?Credentials
    {
        $key = App::env('CRAFT_CLOUD_ACCESS_KEY');
        $secret = App::env('CRAFT_CLOUD_ACCESS_SECRET');

        return $key ? new Credentials(
            $key,
            $secret,
        ) : null;
    }

    public function createClient(array $config = []): S3Client
    {
        $config += [
            'region' => App::env('AWS_REGION') ?? App::env('CRAFT_CLOUD_ACCESS_REGION'),
            'version' => 'latest',
            'http_handler' => new GuzzleHandler(Craft::createGuzzleClient()),
            'endpoint' => App::env('CRAFT_CLOUD_FS_ENDPOINT'),
            'credentials' => $this->createCredentials(),
        ];

        return new S3Client($config);
    }

    public function getClient(): S3Client
    {
        if (!isset($this->_client)) {
            $this->_client = $this->createClient();
        }

        return $this->_client;
    }

    protected function visibility(): string
    {
        return Visibility::PRIVATE;
    }
}
