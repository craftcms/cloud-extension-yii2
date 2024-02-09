<?php

namespace craft\cloud;

use Craft;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\TemplateEvent;
use craft\web\Response as WebResponse;
use craft\web\UrlManager;
use craft\web\View;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Collection;
use samdark\log\PsrMessage;
use yii\base\Exception;
use yii\caching\TagDependency;

class StaticCaching extends \yii\base\Component
{
    public function handleBeforeRenderPageTemplate(TemplateEvent $event): void
    {
        // ignore CP and CLI requests
        if (
            $event->templateMode !== View::TEMPLATE_MODE_SITE ||
            !(Craft::$app->getResponse() instanceof WebResponse)
        ) {
            return;
        }

        // start collecting element cache info
        Craft::$app->getElements()->startCollectingCacheInfo();

        // capture the matched element, if there was one
        /** @var UrlManager $urlManager */
        $urlManager = Craft::$app->getUrlManager();
        $matchedElement = $urlManager->getMatchedElement();
        if ($matchedElement) {
            Craft::$app->getElements()->collectCacheInfoForElement($matchedElement);
        }
    }

    public function handleAfterRenderPageTemplate(TemplateEvent $event): void
    {
        // ignore CP and CLI requests
        if (
            $event->templateMode !== View::TEMPLATE_MODE_SITE ||
            !(Craft::$app->getResponse() instanceof WebResponse)
        ) {
            return;
        }

        /** @var TagDependency|null $dependency */
        /** @var int|null $duration */
        [$dependency, $duration] = Craft::$app->getElements()->stopCollectingCacheInfo();

        if ($dependency?->tags) {
            $this->addCacheTagsToResponse($dependency->tags, $duration);
        }
    }

    public function handleInvalidateCaches(InvalidateElementCachesEvent $event): void
    {
        if (Craft::$app->getResponse() instanceof WebResponse) {
            $this->addCachePurgeTagsToResponse($event->tags ?? []);
        } else {
            // TODO: Support invalidation from CLI
        }
    }

    public function handleRegisterCacheOptions(RegisterCacheOptionsEvent $event): void
    {
        $event->options[] = [
            'key' => 'cloud-static-caches',
            'label' => Craft::t('app', 'Craft Cloud static caches'),
            'action' => [$this, 'purgeAll'],
        ];
    }

    public function purgeAll(): void
    {
        $headers = Collection::make([
            HeaderEnum::CACHE_PURGE->value => '*',
        ]);

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $url = Module::getInstance()->getConfig()->getPreviewDomainUrl();

            if (!$url) {
                throw new Exception('Unable to purge cache from the CLI without a preview domain.');
            }

            $context = Helper::createSigningContext($headers->keys());
            $request = new Request('HEAD', (string) $url, $headers->all());
            Craft::createGuzzleClient()->send(
                $context->signer()->sign($request)
            );

            return;
        }

        $headers->each(function($value, $name) {
            return Craft::$app->getResponse()
                ->getHeaders()
                ->set($name, $value);
        });
    }

    protected function prepareTags(iterable $tags): Collection
    {
        // Header value can't exceed 16KB
        // https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
        $bytes = 0;

        return Collection::make($tags)
            ->map(fn(string $tag) => $this->removeNonPrintableChars($tag))
            ->filter()
            ->sort(SORT_NATURAL)
            ->map(function(string $tag) {
                return Module::getInstance()->getConfig()->getShortEnvironmentId() . $this->hash($tag);
            })
            ->unique()
            ->filter(function($tag) use (&$bytes) {
                // plus one for comma
                $bytes += strlen($tag) + 1;

                return $bytes < 16 * 1024;
            })
            ->values();
    }

    protected function removeNonPrintableChars(string $string): string
    {
        return preg_replace('/[^[:print:]]/', '', $string);
    }

    protected function hash(string $string): ?string
    {
        return sprintf('%x', crc32($string));
    }

    protected function addCacheTagsToResponse(array $tags, $duration = null): void
    {
        $response = Craft::$app->getResponse();

        if (
            $response->isServerError ||
            Craft::$app->getConfig()->getGeneral()->devMode ||
            $this->shouldIgnoreTags($tags)
        ) {
            Craft::info(new PsrMessage('Ignoring cache tags', $tags));

            return;
        }

        $tagsForHeader = $this->prepareTags($tags);

        if ($tagsForHeader->isEmpty() || $duration === null) {
            return;
        }

        Craft::info(new PsrMessage('Adding cache tags to response', $tagsForHeader->all()));

        $tagsForHeader->each(fn(string $tag) => $response->getHeaders()->add(
            HeaderEnum::CACHE_TAG->value,
            $tag,
        ));

        $response->setCacheHeaders($duration, false);
    }

    protected function shouldIgnoreTags(iterable $tags): bool
    {
        return Collection::make($tags)->contains(function(string $tag) {
            return preg_match('/element::craft\\\\elements\\\\\S+::(drafts|revisions)/', $tag);
        });
    }

    protected function addCachePurgeTagsToResponse(array $tags): void
    {
        if ($this->shouldIgnoreTags($tags)) {
            Craft::info(new PsrMessage('Ignoring cache tags', $tags));

            return;
        }

        // Max 30 tags per purge
        // https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
        $tagsForHeader = $this->prepareTags($tags)->slice(0, 30);

        if ($tagsForHeader->isEmpty()) {
            return;
        }

        Craft::info(new PsrMessage('Adding cache purge tags to response', $tagsForHeader->all()));

        $headers = Craft::$app->getResponse()->getHeaders();

        $tagsForHeader->each(fn(string $tag) => $headers->add(
            HeaderEnum::CACHE_PURGE->value,
            $tag,
        ));
    }
}
