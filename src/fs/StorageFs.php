<?php

namespace craft\cloud\fs;

use League\Uri\Components\HierarchicalPath;

class StorageFs extends Fs
{
    public function createBucketPrefix(): HierarchicalPath
    {
        return parent::createBucketPrefix()->append('storage');
    }
}
