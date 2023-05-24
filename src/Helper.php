<?php

namespace craft\cloud;

use Craft;
use craft\helpers\App;
use craft\helpers\StringHelper;
use League\Uri\Contracts\UriInterface;
use League\Uri\Uri;
use League\Uri\UriTemplate;

class Helper
{

    public static function getBuildUrl(string $path = ''): UriInterface
    {
        return self::getCdnUrl("{environmentId}/builds/{buildId}/${path}");
    }

    public static function getCdnUrl(string $path = ''): UriInterface
    {
        $template = new UriTemplate(
            self::collapseSlashes($path),
            [
                'environmentId' => self::getEnvironmentId() ?? '__ENVIRONMENT_ID__',
                'buildId' => Craft::$app->getConfig()->getGeneral()->buildId ?? '__BUILD_ID__',
                'projectId' => Craft::$app->id ?? '__PROJECT_ID__',
            ]
        );

        $baseUrl = StringHelper::ensureRight(App::env('CRAFT_CLOUD_CDN_BASE_URL') ?? 'https://cdn.craft.cloud', '/');

        return Uri::createFromBaseUri(
            $template->expand(),
            $baseUrl,
        );
    }

    public static function getEnvironmentId(): ?string
    {
        return App::env('CRAFT_CLOUD_ENVIRONMENT_ID');
    }

    public static function isCraftCloud(): bool
    {
        return (bool)App::env('AWS_LAMBDA_RUNTIME_API');
    }

    public static function collapseSlashes(string $path): string
    {
        return preg_replace('#/{2,}#', '/', $path);
    }
}
