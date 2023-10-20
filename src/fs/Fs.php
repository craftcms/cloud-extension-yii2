<?php

namespace craft\cloud\fs;

use Aws\Credentials\Credentials;
use Aws\Handler\GuzzleV6\GuzzleHandler;
use Aws\S3\S3Client;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\cloud\Module;
use craft\errors\FsException;
use craft\flysystem\base\FlysystemFs;
use craft\fs\Local;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use Generator;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\Visibility;
use League\Uri\Components\HierarchicalPath;
use League\Uri\Uri;
use Throwable;
use yii\web\BadRequestHttpException;

/**
 *
 * @property-read string $bucketName
 * @property-read string $prefix
 * @property-read ?string $settingsHtml
 */
abstract class Fs extends FlysystemFs
{
    protected static bool $showUrlSetting = false;
    protected ?string $expires = null;
    protected ?Local $localFs = null;
    protected S3Client $client;
    public ?string $subpath = null;
    public ?string $localFsPath = null;
    public ?string $localFsUrl = null;
    public ?string $url = '__URL__';

    /**
     * @inheritDoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['localFsPath'], 'required'];
        $rules[] = [
            'localFsUrl',
            'required',
            'when' => fn(self $fs) => $fs->hasUrls,
        ];

        return $rules;
    }

    protected function getLocalFs(): Local
    {
        $this->localFs = $this->localFs ?? Craft::createObject([
            'class' => Local::class,
            'hasUrls' => $this->hasUrls,
            'path' => $this->localFsPath
                ? Craft::$app->getView()->renderObjectTemplate($this->localFsPath, $this)
                : null,
            'url' => $this->hasUrls && $this->localFsUrl
                ? Craft::$app->getView()->renderObjectTemplate($this->localFsUrl, $this)
                : null,
        ]);

        return $this->localFs;
    }

    protected function useLocalFs(): bool
    {
        return !Module::getInstance()->getConfig()->getUseCloudFs();
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl(): ?string
    {
        if (!$this->hasUrls) {
            return null;
        }

        try {
            return $this->createUrl();
        } catch (FsException $e) {
            return null;
        }
    }

    public function createUrl(string $path = ''): string
    {
        $baseUrl = Module::getInstance()->getConfig()->getUseCloudFs()
            ? Module::getInstance()->getConfig()->cdnBaseUrl
            : $this->getLocalFs()->getRootUrl();

        if (!$baseUrl) {
            throw new FsException('Filesystem is not configured with a valid base URL.');
        }

        return Uri::createFromBaseUri(
            $this->prefixPath($path),
            $baseUrl,
        );
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'localFsPath' => Craft::t('app', 'Base Path'),
            'localFsUrl' => Craft::t('app', 'Base URL'),
        ];
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
                'subpath',
                'localFsPath',
                'localFsUrl',
            ],
        ];

        return $behaviors;
    }

    /**
     * @inheritDoc
     */
    public function settingsAttributes(): array
    {
        return array_merge(parent::settingsAttributes(), [
            'expires',
        ]);
    }

    public function getExpires(): ?string
    {
        return $this->expires;
    }

    public function setExpires(null|string|array $expires): void
    {
        $this->expires = is_array($expires) ? $this->normalizeExpires($expires) : $expires;
    }

    protected function normalizeExpires(array $expires): ?string
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
     * @inheritDoc
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
            $config['Tagging'] = Visibility::PRIVATE;
        }

        return parent::addFileMetadataToConfig($config);
    }

    /**
     * @inheritDoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cloud/fsSettings', [
            'fs' => $this,
            'periods' => Assets::periodList(),
        ]);
    }

    protected function getPrefix(): string
    {
        $segments = [
            Module::getInstance()->getConfig()->getUseCloudFs()
                ? Module::getInstance()->getConfig()->environmentId ?? ''
                : '',
        ];

        return HierarchicalPath::createRelativeFromSegments($segments)
            ->withoutEmptySegments()
            ->withoutTrailingSlash();
    }

    public function prefixPath(string $path = ''): string
    {
        return HierarchicalPath::createRelativeFromSegments([
            $this->getPrefix(),
            $this->subpath ?? '',
            $path,
        ])->withoutEmptySegments()->withoutTrailingSlash();
    }

    public function getBucketName(): ?string
    {
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
        if (!isset($this->client)) {
            $this->client = $this->createClient();
        }

        return $this->client;
    }

    /**
     * @inheritDoc
     * All s3 objects are non-public
     */
    protected function visibility(): string
    {
        return Visibility::PRIVATE;
    }

    public function uploadDirectory(string $path, string $destPath, $config = []): void
    {
        try {
            $config = $this->addFileMetadataToConfig($config);

            $this->getClient()->uploadDirectory(
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
        if ($this->useLocalFs()) {
            throw new BadRequestHttpException();
        }

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
     * @inheritdoc
     * Duping parent to add config…
     * @see https://github.com/craftcms/flysystem/pull/9
     */
    public function copyFile(string $path, string $newPath): void
    {
        if ($this->useLocalFs()) {
            $this->getLocalFs()->copyFile($path, $newPath);
            return;
        }

        try {
            $config = $this->addFileMetadataToConfig([]);
            $this->filesystem()->copy($path, $newPath, $config);
        } catch (FilesystemException|UnableToCopyFile $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritdoc
     * Duping parent method to add config…
     * @see https://github.com/craftcms/flysystem/pull/9
     */
    public function renameFile(string $path, string $newPath): void
    {
        if ($this->useLocalFs()) {
            $this->getLocalFs()->renameFile($path, $newPath);
            return;
        }

        try {
            $config = $this->addFileMetadataToConfig([]);
            $this->filesystem()->move($path, $newPath, $config);
        } catch (FilesystemException|UnableToMoveFile $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritdoc
     * Duping parent method to add config…
     * @see https://github.com/craftcms/flysystem/pull/9
     */
    public function createDirectory(string $path, array $config = []): void
    {
        if ($this->useLocalFs()) {
            $this->getLocalFs()->createDirectory($path, $config);
            return;
        }

        try {
            $config = $this->addFileMetadataToConfig([]);
            $this->filesystem()->createDirectory($path, $config);
        } catch (FilesystemException|UnableToCreateDirectory $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function getFileList(string $directory = '', bool $recursive = true): Generator
    {
        if ($this->useLocalFs()) {
            return $this->getLocalFs()->getFileList($directory, $recursive);
        }

        return parent::getFileList($directory, $recursive);
    }

    /**
     * @inheritDoc
     */
    public function getFileSize(string $uri): int
    {
        if ($this->useLocalFs()) {
            return $this->getLocalFs()->getFileSize($uri);
        }

        return parent::getFileSize($uri);
    }

    /**
     * @inheritDoc
     */
    public function getDateModified(string $uri): int
    {
        if ($this->useLocalFs()) {
            return $this->getLocalFs()->getDateModified($uri);
        }

        return parent::getDateModified($uri);
    }


    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, array $config = []): void
    {
        if ($this->useLocalFs()) {
            $this->getLocalFs()->write($path, $contents, $config);
            return;
        }

        parent::write($path, $contents, $config);
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        if ($this->useLocalFs()) {
            return $this->getLocalFs()->read($path);
        }

        return parent::read($path);
    }

    /**
     * @inheritDoc
     */
    public function writeFileFromStream(string $path, $stream, array $config = []): void
    {
        if ($this->useLocalFs()) {
            $this->getLocalFs()->writeFileFromStream($path, $stream, $config);
            return;
        }

        parent::writeFileFromStream($path, $stream, $config);
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        if ($this->useLocalFs()) {
            return $this->getLocalFs()->fileExists($path);
        }

        return parent::fileExists($path);
    }

    /**
     * @inheritDoc
     */
    public function deleteFile(string $path): void
    {
        if ($this->useLocalFs()) {
            $this->getLocalFs()->deleteFile($path);
            return;
        }

        parent::deleteFile($path);
    }

    /**
     * @inheritDoc
     */
    public function getFileStream(string $uriPath)
    {
        if ($this->useLocalFs()) {
            return $this->getLocalFs()->getFileStream($uriPath);
        }

        return parent::getFileStream($uriPath);
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $path): bool
    {
        if ($this->useLocalFs()) {
            return $this->getLocalFs()->directoryExists($path);
        }

        return parent::directoryExists($path);
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        if ($this->useLocalFs()) {
            $this->getLocalFs()->deleteDirectory($path);
            return;
        }

        parent::deleteDirectory($path);
    }
}
