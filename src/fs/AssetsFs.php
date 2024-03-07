<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Components\HierarchicalPath;

class AssetsFs extends CdnFs
{
    public ?string $localFsPath = '@webroot/uploads';
    public ?string $localFsUrl = '@web/uploads';

    public function init(): void
    {
        $this->useLocalFs = !Module::getInstance()->getConfig()->getUseAssetCdn();
        parent::init();
    }

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return 'Craft Cloud';
    }

    public function getPrefix(): string
    {
        if (!Module::getInstance()->getConfig()->getUseAssetCdn()) {
            return '';
        }

        return HierarchicalPath::fromRelative(
            parent::getPrefix(),
            'assets',
        )->withoutEmptySegments()->withoutTrailingSlash();
    }
}
