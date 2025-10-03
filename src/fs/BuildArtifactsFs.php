<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Components\HierarchicalPath;

class BuildArtifactsFs extends BuildsFs
{
    public bool $hasUrls = true;

    // public function init(): void
    // {
    //     $this->useLocalFs = !Module::getInstance()->getConfig()->useArtifactCdn;
    //     $this->localFsUrl = Module::getInstance()->getConfig()->artifactBaseUrl ?? $this->localFsUrl;
    //     parent::init();
    // }

    public function createBucketPrefix(): HierarchicalPath
    {
        return parent::createBucketPrefix()->append('artifacts');
    }
}
