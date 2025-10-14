<?php

namespace craft\cloud\fs;

use craft\cloud\Helper;
use craft\cloud\Module;
use League\Uri\Contracts\SegmentedPathInterface;

class BuildArtifactsFs extends BuildsFs
{
    public bool $hasUrls = true;
    public ?string $localFsPath = '@webroot';
    public ?string $localFsUrl = '@web';

    public function init(): void
    {
        parent::init();
        $this->useLocalFs = !Helper::isCraftCloud();
        $this->baseUrl = Module::getInstance()->getConfig()->artifactBaseUrl;

        if ($this->useLocalFs && !$this->baseUrl) {
            $this->baseUrl = $this->getLocalFs()->getRootUrl();
        }
    }

    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('artifacts');
    }
}
