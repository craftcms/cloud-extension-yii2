<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Components\HierarchicalPath;

class BuildArtifactsFs extends BuildsFs
{
    public ?string $localFsPath = '@webroot';
    public ?string $localFsUrl = '@web';

    public function init(): void
    {
        $this->useLocalFs = !Module::getInstance()->getConfig()->useArtifactCdn;
        $this->localFsUrl = Module::getInstance()->getConfig()->artifactBaseUrl ?? $this->localFsUrl;
        parent::init();
    }

    public function getPrefix(): string
    {
        if (!Module::getInstance()->getConfig()->useArtifactCdn) {
            return '';
        }

        return HierarchicalPath::fromRelative(
            parent::getPrefix(),
            'artifacts',
        )->withoutEmptySegments()->withoutTrailingSlash();
    }
}
