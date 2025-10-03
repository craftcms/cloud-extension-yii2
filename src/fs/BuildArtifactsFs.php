<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Contracts\SegmentedPathInterface;

class BuildArtifactsFs extends BuildsFs
{
    public bool $hasUrls = true;

    public function init(): void
    {
        $this->baseUrl = Module::getInstance()->getConfig()->artifactBaseUrl;
        parent::init();
    }

    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('artifacts');
    }
}
