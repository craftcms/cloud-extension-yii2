<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Components\HierarchicalPath;

abstract class BuildsFs extends Fs
{
    protected ?string $expires = '1 years';
    public bool $hasUrls = true;

    public function getPrefix(): string
    {
        return HierarchicalPath::fromRelative(
            parent::getPrefix(),
            'builds',
            Module::getInstance()->getConfig()->buildId,
        )->withoutEmptySegments()->withoutTrailingSlash();
    }
}
