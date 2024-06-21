<?php

namespace craft\cloud;

use Craft;
use craft\base\ElementInterface;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use craft\web\View;
use Illuminate\Support\Collection;
use League\Uri\Components\Path;
use samdark\log\PsrMessage;
use yii\base\Event;
use yii\caching\TagDependency;

/**
 * Static Cache tags can appear in the `Cache-Tag` and `Cache-Purge-Tag` headers.
 * The values are comma-separated and can be in several formats:
 *
 * - Added by the gateway:
 *   - `{environmentId}`
 *   - `{environmentId}:{uri}` (URI has a leading and no trailing slash)
 * - Added by the CDN:
 *    - `cdn:{environmentId}`
 *    - `cdn:{environmentId}:{objectKey}` (object key has no leading slash)
 * - Added by Craft:
 *   - `{environmentShortId}{hashed}`
 */
class StaticCache extends \yii\base\Component
{
    public const CDN_PREFIX = 'cdn:';
    private ?int $cacheDuration = null;
    private Collection $tags;
    private Collection $tagsToPurge;
    private bool $collectingCacheInfo = false;

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
            [$this, 'handleSaveElement'],
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            [$this, 'handleDeleteElement'],
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
        $this->collectingCacheInfo = true;
    }

    public function handleAfterPrepareWebResponse(Event $event): void
    {
        if (!$this->isCacheable()) {
            return;
        }

        if ($this->collectingCacheInfo) {
            /** @var TagDependency|null $dependency */
            /** @var int|null $duration */
            [$dependency, $duration] = Craft::$app->getElements()->stopCollectingCacheInfo();
            $this->collectingCacheInfo = false;
            $tags = $dependency?->tags ?? [];
            $this->tags->push(...$tags);
            $this->cacheDuration = $duration;
        }

        // $this->addCacheHeadersToWebResponse();
    }

    public function handleBeforeRenderPageTemplate(TemplateEvent $event): void
    {
        /** @var UrlManager $urlManager */
        $urlManager = Craft::$app->getUrlManager();
        $matchedElement = $urlManager->getMatchedElement();

        if ($matchedElement) {
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

        $this->tagsToPurge->push(...$event->tags);
    }

    public function handleRegisterCacheOptions(RegisterCacheOptionsEvent $event): void
    {
        $event->options[] = [
            'key' => 'craft-cloud-caches',
            'label' => Craft::t('app', 'Craft Cloud caches'),
            'action' => [$this, 'purgeAll'],
        ];
    }

    public function handleSaveElement(ElementEvent $event): void
    {
        $this->purgeElementUri($event->element);
    }

    public function handleDeleteElement(ElementEvent $event): void
    {
        $this->purgeElementUri($event->element);
    }

    public function purgeAll(): void
    {
        $this->purgeGateway();
        $this->purgeCdn();
    }

    public function purgeGateway(): void
    {
        $tag = StaticCacheTag::create(
            Module::getInstance()->getConfig()->environmentId,
        )->minify(false);

        $this->tagsToPurge->push($tag);
    }

    public function purgeCdn(): void
    {
        $tag = StaticCacheTag::create(
            Module::getInstance()->getConfig()->environmentId,
        )->withPrefix(self::CDN_PREFIX)->minify(false);

        $this->tagsToPurge->push($tag);
    }

    private function purgeElementUri(ElementInterface $element): void
    {
        if (ElementHelper::isDraftOrRevision($element) || !$element->uri) {
            return;
        }

        $uri = $element->getIsHomepage()
            ? '/'
            : Path::new($element->uri)->withLeadingSlash()->withoutTrailingSlash();

        $tag = StaticCacheTag::create($uri)
            ->withPrefix(Module::getInstance()->getConfig()->environmentId . ':')
            ->minify(false);

        $this->tagsToPurge->prepend($tag);
    }

    private function addCacheHeadersToWebResponse(): void
    {
        $this->cacheDuration = $this->cacheDuration ?? Module::getInstance()->getConfig()->staticCacheDuration;
        $headers = Craft::$app->getResponse()->getHeaders();

        Craft::info(new PsrMessage('Setting cache headers', [
            'duration' => $this->cacheDuration,
        ]));

        Craft::$app->getResponse()->setCacheHeaders(
            $this->cacheDuration,
            false,
        );

        // Capture, remove any existing headers so we can prepare them
        $existingTagsFromHeader = Collection::make($headers->get(HeaderEnum::CACHE_TAG->value, first: false) ?? []);
        $headers->remove(HeaderEnum::CACHE_TAG->value);
        $this->tags = $this->tags->push(...$existingTagsFromHeader);

        // Header value can't exceed 16KB
        // https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
        $this->prepareTags(...$this->tags)
            ->tap(fn(Collection $tags) => $this->limitTagsToBytes(16 * 1024, ...$tags))
            ->each(fn(string $tag) => $headers->add(
                HeaderEnum::CACHE_TAG->value,
                $tag,
            ));
    }

    public function purgeTags(string|StaticCacheTag ...$tags): void
    {
        $tags = Collection::make($tags);
        $response = Craft::$app->getResponse();
        $isWebResponse = $response instanceof \craft\web\Response;

        // Add any existing tags from the response headers
        if ($isWebResponse) {
            $existingTagsFromHeader = $response->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value, first: false) ?? [];
            $tags->push(...$existingTagsFromHeader);
            $response->getHeaders()->remove(HeaderEnum::CACHE_PURGE_TAG->value);
        }

        $tags = $this->prepareTags(...$tags);

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

    private function prepareTags(string|StaticCacheTag ...$tags): Collection
    {
        return Collection::make($tags)
            ->map(function(string|StaticCacheTag $tag): string {
                $tag = is_string($tag) ? StaticCacheTag::create($tag) : $tag;

                return $tag->getValue();
            })
            ->filter()
            ->unique();
    }

    private function limitTagsToBytes(int $limit, string ...$tags): Collection
    {
        $bytes = 0;

        return Collection::make($tags)
            ->filter()
            ->unique()
            ->filter(function(string $tag) use (&$bytes, $limit) {
                // plus one for comma
                $bytes += strlen($tag) + 1;

                return $bytes < $limit;
            })
            ->values();
    }
}
