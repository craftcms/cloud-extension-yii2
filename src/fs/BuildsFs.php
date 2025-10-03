<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Components\HierarchicalPath;

abstract class BuildsFs extends Fs
{
    public bool $hasUrls = true;
    protected ?string $expires = '1 years';

    public function createBucketPrefix(): HierarchicalPath
    {
        return parent::createBucketPrefix()
            ->append('builds')
            ->append(Module::getInstance()->getConfig()->buildId);
    }
}
