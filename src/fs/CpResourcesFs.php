<?php

namespace craft\cloud\fs;

use League\Uri\Components\HierarchicalPath;

class CpResourcesFs extends BuildsFs
{
    public function createBucketPrefix(): HierarchicalPath
    {
        return parent::createBucketPrefix()->append('cpresources');
    }
}
