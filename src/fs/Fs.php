<?php

namespace craft\cloud\fs;

use Aws\Credentials\Credentials;
use Aws\Handler\GuzzleV6\GuzzleHandler;
use Aws\S3\S3Client;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\cloud\Helper;
use craft\cloud\Module;
use craft\errors\FsException;
use craft\flysystem\base\FlysystemFs;
use craft\helpers\App;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\Visibility;
use League\Uri\Components\HierarchicalPath;
use League\Uri\Uri;
use Throwable;

/**
 *
 * @property-read string $bucketName
 * @property-read string $prefix
 * @property-read S3Client $client
 * @property-read ?string $settingsHtml
 */
class Fs extends FlysystemFs
{

    protected static bool $showUrlSetting = false;
    protected ?string $expires = null;
    private S3Client $_client;
    public ?string $subfolder = null;
    public const TAG_PRIVATE = 'private';

    /**
     * @inheritdoc
     */
    public function getRootUrl(): ?string
    {
        if (!$this->hasUrls) {
            return null;
        }

        return $this->createUrl('');
    }

    public function createUrl(string $path): string
    {
        return Uri::createFromBaseUri(
            $this->prefixPath($path),
            Module::getInstance()->getConfig()->getCdnBaseUrl(),
        );
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

    public function getBasePath(): HierarchicalPath
    {
        return HierarchicalPath::createFromString(Module::getInstance()->getConfig()->environmentId ?: '');
    }

    public function getExpires(): ?string
    {
        return $this->expires;
    }

    public function setExpires(null|string|array $expires): void
    {
        $this->expires = is_array($expires) ? $this->normalizeExpires($expires) : $expires;
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
    protected function createAdapter(): AwsS3V3Adapter
    {
        return new AwsS3V3Adapter(
            client: $this->getClient(),
            bucket: $this->getBucketName(),
            prefix: $this->prefixPath(),
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
        if (!empty($this->getExpires()) && DateTimeHelper::isValidIntervalString($this->getExpires())) {
            $expires = new DateTime();
            $now = new DateTime();
            $expires->modify('+' . $this->getExpires());
            $diff = (int)$expires->format('U') - (int)$now->format('U');
            $config['CacheControl'] = "max-age=$diff";
        }

        if (!$this->hasUrls) {
            $config['Tagging'] = self::TAG_PRIVATE;
        }

        return parent::addFileMetadataToConfig($config);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cloud/fsSettings', [
            'fs' => $this,
            'periods' => Assets::periodList(),
        ]);
    }

    public function prefixPath(?string $path = null): HierarchicalPath
    {
        return HierarchicalPath::createRelativeFromSegments([
            $this->getBasePath(),
            $this->subfolder ?? '',
            $path ?? '',
        ])->withoutEmptySegments()->withoutTrailingSlash();
    }

    public function getBucketName(): ?string
    {
        if (!Helper::isCraftCloud()) {
            return '';
        }

        return Module::getInstance()->getConfig()->projectId;
    }

    public function createCredentials(): ?Credentials
    {
        $key = Module::getInstance()->getConfig()->accessKey;

        return $key ? new Credentials(
            $key,
            Module::getInstance()->getConfig()->accessSecret,
        ) : null;
    }

    public function createClient(array $config = []): S3Client
    {
        $config = array_merge(
            [
                'region' => Module::getInstance()->getConfig()->getRegion(),
                'version' => 'latest',
                'http_handler' => new GuzzleHandler(Craft::createGuzzleClient()),
                'credentials' => $this->createCredentials(),
            ],
            Module::getInstance()->getConfig()->s3ClientOptions,
            $config
        );

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

    public function uploadDirectory(string $path, string $destPath, $config = [])
    {
        try {
            $config = $this->addFileMetadataToConfig($config);

            return $this->getClient()->uploadDirectory(
                $path,
                $this->getBucketName(),
                $this->prefixPath($destPath),
                $config,
            );
        } catch (Throwable $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    public function presignedUrl(string $command, string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        try {
            $commandConfig = $this->addFileMetadataToConfig($config);

            $command = $this->client->getCommand($command, [
                'Bucket' => $this->getBucketName(),
                'Key' => $this->prefixPath($path),
            ] + $commandConfig);

            $request = $this->client->createPresignedRequest(
                $command,
                $expiresAt,
            );

            return (string)$request->getUri();
        } catch (Throwable $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Duping parent to add config…
     * See https://github.com/craftcms/flysystem/pull/9
     */
    public function copyFile(string $path, string $newPath): void
    {
        try {
            $config = $this->addFileMetadataToConfig([]);
            $this->filesystem()->copy($path, $newPath, $config);
        } catch (FilesystemException | UnableToCopyFile $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Duping parent methods to add config…
     * See https://github.com/craftcms/flysystem/pull/9
     */

    /**
     * @inheritdoc
     */
    public function renameFile(string $path, string $newPath): void
    {
        try {
            $config = $this->addFileMetadataToConfig([]);
            $this->filesystem()->move($path, $newPath, $config);
        } catch (FilesystemException | UnableToMoveFile $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function createDirectory(string $path, array $config = []): void
    {
        try {
            $config = $this->addFileMetadataToConfig([]);
            $this->filesystem()->createDirectory($path, $config);
        } catch (FilesystemException | UnableToCreateDirectory $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }
}
