<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use craft\helpers\App;
use League\Uri\Contracts\SegmentedPathInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Modifier;

class AssetsFs extends Fs
{
    protected ?string $expires = '1 years';
    public ?string $localFsPath = '@webroot/uploads';
    public ?string $localFsUrl = '/uploads';
    protected static bool $showUrlSetting = true;

    public function init(): void
    {
        $this->useLocalFs = !Module::getInstance()->getConfig()->useAssetCdn;
        parent::init();
    }

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return 'Craft Cloud';
    }

    public function getRootUrl(): ?string
    {
        if (!$this->hasUrls) {
            return null;
        }

        $url = App::parseEnv($this->url);

        if (is_string($url)) {
            $url = rtrim($url, '/');
        }

        return $url ? "$url/$this->subpath/" : null;
    }

    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('assets');
    }
}
