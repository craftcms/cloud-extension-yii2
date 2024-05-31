<?php

namespace craft\cloud\web;

use Craft;
use craft\cloud\fs\CpResourcesFs;
use craft\cloud\Helper;
use craft\cloud\Module;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use League\Uri\Components\HierarchicalPath;
use League\Uri\Modifier;

class AssetManager extends \craft\web\AssetManager
{
    public bool $cacheSourcePaths = false;

    public function init(): void
    {
        $this->preparePaths();
        parent::init();
    }

    public function publish($path, $options = []): array
    {
        $this->preparePaths();
        return parent::publish($path, $options);
    }

    protected function preparePaths(): void
    {
        $this->basePath = Craft::getAlias($this->basePath);

        if (!Helper::isCraftCloud()) {
            FileHelper::createDirectory($this->basePath);
        }

        if (Module::getInstance()->getConfig()->useAssetBundleCdn) {
            $this->baseUrl = Modifier::from((new CpResourcesFs())->createUrl())->removeTrailingSlash();
        }
    }

    protected function hash($path): string
    {
        $dir = is_file($path) ? dirname($path) : $path;
        $rebrandPath = Craft::$app->getPath()->getRebrandPath();

        // Account for rebrand, as it lives in @storage by default,
        // which will be different in Cloud runtime vs. Cloud build.
        if (str_starts_with($dir, $rebrandPath)) {
            return HierarchicalPath::new(StringHelper::removeLeft($dir, $rebrandPath))
                ->prepend('rebrand')
                ->withoutTrailingSlash()
                ->toString();
        }

        // @phpstan-ignore-next-line
        $alias = Craft::alias($dir);

        return FileHelper::sanitizeFilename(
            preg_replace('/\/|@/', '-', $alias),
            ['asciiOnly' => true]
        );
    }
}
