<?php

namespace craft\cloud;

use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use Twig\Markup;

class Esi
{
    public function __construct(
        private readonly string $template,
        private readonly array $variables = [],
        private readonly bool $renderEsiTag = true,
    ) {
    }

    public function __invoke(): Markup
    {
        return Template::raw($this->getHtml());
    }

    private function getHtml(): string
    {
        if (!$this->renderEsiTag) {
            return Craft::$app->getView()->renderTemplate($this->template, $this->variables);
        }

        Helper::enableEsi();

        $url = UrlHelper::actionUrl('cloud/templates/render', [
            'template' => $this->template,
            'variables' => $this->variables,
        ]);

        return sprintf('<esi:include src="%s" />', $this->signUrl($url));
    }

    private function signUrl(string $url): string
    {
        return UrlHelper::urlWithParams($url, [
            'signature' => 'TODO'
        ]);
    }
}
