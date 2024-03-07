<?php

namespace craft\cloud\cli\controllers;

use craft\cloud\HeaderEnum;
use craft\cloud\Helper;
use craft\console\Controller;
use Illuminate\Support\Collection;
use yii\console\ExitCode;

class StaticCacheController extends Controller
{
    public function actionPurgePrefixes(string ...$prefixes): int
    {
        $this->do('Purging prefixes', function() use ($prefixes) {
            $headers = Collection::make([
                HeaderEnum::CACHE_PURGE_PREFIX->value => implode(',', $prefixes),
            ]);

            Helper::makeGatewayApiRequest($headers);
        });

        return ExitCode::OK;
    }

    public function actionPurgeTags(string ...$tags): int
    {
        $this->do('Purging tags', function() use ($tags) {
            $headers = Collection::make([
                HeaderEnum::CACHE_PURGE_TAG->value => implode(',', $tags),
            ]);

            Helper::makeGatewayApiRequest($headers);
        });

        return ExitCode::OK;
    }
}
