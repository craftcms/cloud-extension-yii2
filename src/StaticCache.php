<?php

namespace craft\cloud;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\TemplateEvent;
use craft\services\Elements;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use craft\web\View;
use Illuminate\Support\Collection;
use samdark\log\PsrMessage;
use yii\base\Event;
use yii\caching\TagDependency;

class StaticCache extends \yii\base\Component
{
    private ?int $cacheDuration = null;
    private ?ElementInterface $matchedElement = null;
    private Collection $tags;
    private Collection $tagsToPurge;

    public function init(): void
    {
        $this->tags = Collection::make();
        $this->tagsToPurge = Collection::make();
    }

    public function registerEventHandlers(): void
    {
        Event::on(
            \craft\web\Application::class,
            \craft\web\Application::EVENT_INIT,
            [$this, 'handleInitWebApplication'],
        );

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            [$this, 'handleBeforeRenderPageTemplate'],
        );

        Event::on(
            \craft\web\Response::class,
            \yii\web\Response::EVENT_AFTER_PREPARE,
            [$this, 'handleAfterPrepareWebResponse'],
        );

        Event::on(
            Elements::class,
            Elements::EVENT_INVALIDATE_CACHES,
            [$this, 'handleInvalidateElementCaches'],
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            [$this, 'handleUpdateElement'],
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            [$this, 'handleUpdateElement'],
        );

        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            [$this, 'handleRegisterCacheOptions'],
        );

        Craft::$app->onAfterRequest(function() {
            if ($this->tagsToPurge->isNotEmpty()) {
                $this->purgeTags(...$this->tagsToPurge);
            }
        });
    }

    public function handleInitWebApplication(Event $event): void
    {
        if (!$this->isCacheable()) {
            return;
        }

        Craft::$app->getElements()->startCollectingCacheInfo();
    }

    public function handleAfterPrepareWebResponse(Event $event): void
    {
        if (!$this->isCacheable()) {
            return;
        }

        /** @var TagDependency|null $dependency */
        /** @var int|null $duration */
        [$dependency, $duration] = Craft::$app->getElements()->stopCollectingCacheInfo();
        $tags = $dependency?->tags ?? [];
        $this->tags->push(...$tags);

        // Don't override the cache duration if it's already set
        $this->cacheDuration = $this->cacheDuration ?? $duration;

        $this->addCacheHeadersToWebResponse();
    }

    public function handleBeforeRenderPageTemplate(TemplateEvent $event): void
    {
        /** @var UrlManager $urlManager */
        $urlManager = Craft::$app->getUrlManager();
        $matchedElement = $urlManager->getMatchedElement();

        if ($matchedElement) {
            $this->matchedElement = $matchedElement;
            Craft::$app->getElements()->collectCacheInfoForElement($matchedElement);
        }
    }

    public function handleInvalidateElementCaches(InvalidateElementCachesEvent $event): void
    {
        $skip = Collection::make($event->tags)->contains(function(string $tag) {
            return preg_match('/element::craft\\\\elements\\\\\S+::(drafts|revisions)/', $tag);
        });

        if ($skip) {
            return;
        }

        $tags = $this->prepareTags(...$event->tags);
        $this->tagsToPurge->push(...$tags);
    }

    public function handleRegisterCacheOptions(RegisterCacheOptionsEvent $event): void
    {
        $event->options[] = [
            'key' => 'craft-cloud-caches',
            'label' => Craft::t('app', 'Craft Cloud caches'),
            'action' => [$this, 'purgeAll'],
        ];
    }

    public function handleUpdateElement(ElementEvent $event): void
    {
        $element = $event->element;

        if (!$element->uri) {
            return;
        }

        $this->tagsToPurge->push($element->uri);
    }

    public function purgeAll(): void
    {
        $this->tagsToPurge->push(Module::getInstance()->getConfig()->environmentId);
    }

    private function addCacheHeadersToWebResponse(): void
    {
        $this->cacheDuration = $this->cacheDuration ?? Craft::$app->getConfig()->getGeneral()->cacheDuration;
        $headers = Craft::$app->getResponse()->getHeaders();

        Craft::info(new PsrMessage('Setting cache headers', [
            'duration' => $this->cacheDuration,
        ]));

        Craft::$app->getResponse()->setCacheHeaders(
            $this->cacheDuration,
            false,
    );

        // Capture and remove any existing headers so we can prepare them
        $existingTagsFromHeader = Collection::make($headers->get(HeaderEnum::CACHE_TAG->value, first: false) ?? []);
        $headers->remove(HeaderEnum::CACHE_TAG->value);

        // TODO: leading/trailing slashes?
        $uri = ($this->matchedElement?->uri ?? Craft::$app->getRequest()->getFullPath()) ?: Element::HOMEPAGE_URI;
        $this->tags = $this->tags
            ->push(...$existingTagsFromHeader)
            ->prepend($uri);

        // TODO: should I reassign this back to $this->tags with prepared values?
        $tags = $this->prepareTags(...$this->tags)
            ->prepend(Module::getInstance()->getConfig()->environmentId);

        // Header value can't exceed 16KB
        // https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
        $this->limitTagsToBytes(16 * 1024, ...$tags)
            ->each(fn(string $tag) => $headers->add(
                HeaderEnum::CACHE_TAG->value,
                $tag,
            ));
    }

    public function purgeTags(string ...$tags): void
    {
        $tags = Collection::make($tags);
        $response = Craft::$app->getResponse();
        $isWebResponse = $response instanceof \craft\web\Response;

        // Add any existing tags from the response headers
        if ($isWebResponse) {
            $existingTagsFromHeader = $response->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value, first: false) ?? [];
            $tags->push(...$this->prepareTags(...$existingTagsFromHeader));
            $response->getHeaders()->remove(HeaderEnum::CACHE_PURGE_TAG->value);
        }

        $tags = $tags->filter()->unique();

        if ($tags->isEmpty()) {
            return;
        }

        Craft::info(new PsrMessage('Purging tags', [
            'tags' => $tags->all(),
        ]));

        if ($isWebResponse) {
            $tags->each(fn(string $tag) => $response->getHeaders()->add(
                HeaderEnum::CACHE_PURGE_TAG->value,
                $tag,
            ));

            return;
        }

        // TODO: make sure we don't go over max header size
        Helper::makeGatewayApiRequest([
            HeaderEnum::CACHE_PURGE_TAG->value => $tags->implode(','),
        ]);
    }

    public function purgeUrlPrefixes(string ...$urlPrefixes): void
    {
        $urlPrefixes = Collection::make($urlPrefixes)->filter()->unique();

        if ($urlPrefixes->isEmpty()) {
            return;
        }

        Craft::info(new PsrMessage('Purging URL prefixes', [
            'urlPrefixes' => $urlPrefixes->all(),
        ]));

        // TODO: make sure we don't go over max header size
        Helper::makeGatewayApiRequest([
            HeaderEnum::CACHE_PURGE_PREFIX->value => $urlPrefixes->implode(','),
        ]);
    }

    private function isCacheable(): bool
    {
        $response = Craft::$app->getResponse();

        return
            Craft::$app->getView()->templateMode === View::TEMPLATE_MODE_SITE &&
            $response instanceof \craft\web\Response &&
            !$response->getIsServerError();
    }

    private function removeNonPrintableChars(string $string): string
    {
        return preg_replace('/[^[:print:]]/', '', $string);
    }

    private function prepareTags(string ...$tags): Collection
    {
        return Collection::make($tags)
            ->map(fn(string $tag) => $this->prepareTag($tag))
            ->filter()
            ->unique();
    }

    private function prepareTag(string $tag): string
    {
        if (Module::getInstance()->getConfig()->getDevMode()) {
            return $tag;
        }

        $tag = $this->removeNonPrintableChars($tag);
        $hashed = sprintf('%x', crc32($tag));

        return Module::getInstance()->getConfig()->getShortEnvironmentId() . $hashed;
    }

    private function limitTagsToBytes(int $limit, string ...$tags): Collection
    {
        $bytes = 0;

        return Collection::make($tags)
            ->filter()
            ->unique()
            ->filter(function($tag) use (&$bytes, $limit) {
                // plus one for comma
                $bytes += strlen($tag) + 1;

                return $bytes < $limit;
            })
            ->values();
    }
}
