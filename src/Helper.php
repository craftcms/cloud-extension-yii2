<?php

namespace craft\cloud;

use craft\cloud\fs\BuildArtifactsFs;
use craft\helpers\App;

class Helper
{
    /**
     * With local Bref, AWS_LAMBDA_RUNTIME_API is only set from web requests,
     * while LAMBDA_TASK_ROOT is set for both.
     */
    public static function isCraftCloud(): bool
    {
        return (bool)App::env('AWS_LAMBDA_RUNTIME_API') || App::env('LAMBDA_TASK_ROOT');
    }

    public static function artifactUrl(string $path): string
    {
        if (!self::isCraftCloud()) {
            return $path;
        }

        return (new BuildArtifactsFs())->createUrl($path);
    }
}
