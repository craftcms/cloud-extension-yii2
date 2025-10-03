<?php

namespace craft\cloud\fs;

use League\Uri\Components\HierarchicalPath;

class TmpFs extends StorageFs
{
    public function createBucketPrefix(): HierarchicalPath
    {
        return parent::createBucketPrefix()->append('tmp');
    }
}
