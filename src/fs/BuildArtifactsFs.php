<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Components\HierarchicalPath;

class BuildArtifactsFs extends BuildsFs
{
    public bool $hasUrls = true;

    public function init(): void
    {
        $this->baseUrl = Module::getInstance()->getConfig()->artifactBaseUrl;
        parent::init();
    }

    public function createBucketPrefix(): HierarchicalPath
    {
        return parent::createBucketPrefix()->append('artifacts');
    }
}
