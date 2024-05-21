<?php

namespace craft\cloud\twig;

use Craft;
use craft\cloud\Helper;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        return [
            'cloud' => new CloudVariable(),
            'isCraftCloud' => $this->isCraftCloud(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('artifactUrl', [$this, 'artifactUrl']),
        ];
    }

    /**
     * @deprecated in 1.4.8
     */
    public function isCraftCloud(): bool
    {
        Craft::$app->getDeprecator()->log(
            __METHOD__,
            'The `isCraftCloud` Twig global has been deprecated. Use `cloud.isCraftCloud` instead.',
        );

        return Helper::isCraftCloud();
    }

    /**
     * @deprecated in 1.4.8
     */
    public function artifactUrl(string $path): string
    {
        Craft::$app->getDeprecator()->log(
            __METHOD__,
            'The `artifactUrl` Twig function has been deprecated. Use `cloud.artifactUrl` instead.',
        );
        return Helper::artifactUrl($path);
    }
}
