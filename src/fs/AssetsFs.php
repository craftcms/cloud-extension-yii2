<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Components\HierarchicalPath;

class AssetsFs extends Fs
{
    protected ?string $expires = '1 years';
    public ?string $localFsPath = '@webroot/uploads';
    public ?string $localFsUrl = '/uploads';

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

    public function createBucketPrefix(): HierarchicalPath
    {
        return parent::createBucketPrefix()->append('assets');
    }
}
