<?php

namespace craft\cloud\fs;

use craft\cloud\HeaderEnum;
use craft\cloud\Helper;
use Illuminate\Support\Collection;

class CdnFs extends Fs
{
    protected ?string $expires = '1 years';
    public bool $hasUrls = true;

    /**
     * @inheritDoc
     */
    protected function invalidateCdnPath(string $path): bool
    {
        try {
            Helper::makeGatewayApiRequest(Collection::make([
                HeaderEnum::CACHE_PURGE_TAG->value => $this->prefixPath($path),
            ]));

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
