<?php

namespace craft\cloud;

use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use InvalidArgumentException;
use Twig\Markup;

class Esi
{
    public function __construct(
        private readonly UrlSigner $urlSigner,
        private readonly bool $useEsi = true,
    ) {
    }

    /**
     * Prepare response for ESI processing by setting the Surrogate-Control header
     * Note: The Surrogate-Control header will cause Cloudflare to ignore
     * the Cache-Control header: https://developers.cloudflare.com/cache/concepts/cdn-cache-control/#header-precedence
     */
    public function prepareResponse(): void
    {
        Craft::$app->getResponse()->getHeaders()->setDefault(
            HeaderEnum::SURROGATE_CONTROL->value,
            'content="ESI/1.0"',
        );
    }

    public function render(string $template, array $variables = []): Markup
    {
        $this->validateVariables($variables);

        if (!$this->useEsi) {
            return Template::raw(
                Craft::$app->getView()->renderTemplate($template, $variables)
            );
        }

        $this->prepareResponse();

        $url = UrlHelper::actionUrl('cloud/templates/render', [
            'template' => $template,
            'variables' => $variables,
        ]);

        $signedUrl = $this->urlSigner->sign($url);

        $html = sprintf('<esi:include src="%s" />', $signedUrl);

        Craft::info(['Rendering ESI', $html], __METHOD__);

        return Template::raw($html);
    }

    private function validateVariables(array $variables): void
    {
        foreach ($variables as $value) {
            if (is_array($value)) {
                $this->validateVariables($value);
            } elseif (!is_scalar($value) && !is_null($value)) {
                $type = get_debug_type($value);

                throw new InvalidArgumentException(
                    "Value must be a primitive value or array, {$type} given."
                );
            }
        }
    }
}
