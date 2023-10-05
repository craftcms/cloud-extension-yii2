<?php

namespace craft\cloud\fs;

use League\Uri\Components\HierarchicalPath;

class StorageFs extends Fs
{
    public bool $hasUrls = false;

    public function getPrefix(): string
    {
        return HierarchicalPath::createRelativeFromSegments([
            parent::getPrefix(),
            'storage',
        ])->withoutEmptySegments()->withoutTrailingSlash();
    }
}
