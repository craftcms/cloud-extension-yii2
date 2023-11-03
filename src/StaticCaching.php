<?php

namespace craft\cloud;

use Craft;
use craft\events\InvalidateElementCachesEvent;
use craft\events\TemplateEvent;
use craft\web\UrlManager;
use craft\web\View;
use Illuminate\Support\Collection;
use yii\caching\TagDependency;

class StaticCaching
{
    public static function beforeRenderPageTemplate(TemplateEvent $event): void
    {
        // ignore CP requests
        if ($event->templateMode !== View::TEMPLATE_MODE_SITE) {
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

    public static function afterRenderPageTemplate(TemplateEvent $event): void
    {
        // ignore CP requests
        if ($event->templateMode !== View::TEMPLATE_MODE_SITE) {
            return;
        }

        /** @var TagDependency|null $dependency */
        /** @var int|null $duration */
        [$dependency, $duration] = Craft::$app->getElements()->stopCollectingCacheInfo();

        static::addCacheTagsToResponse($dependency?->tags, $duration);

        // Temporary fix
        Craft::$app->getResponse()->getCookies()->removeAll();
    }

    public static function onInvalidateCaches(InvalidateElementCachesEvent $event): void
    {
        static::addCachePurgeToResponse(PurgeModeEnum::TAG);
        static::addCacheTagsToResponse($event->tags ?? []);
    }

    public static function minifyCacheTags(array $tags): Collection
    {
        // TODO: can't exceed 16KB
        // https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
        return Collection::make($tags)
            ->map(fn(string $tag) => static::minifyCacheTag($tag))
            ->filter()
            ->unique();
    }

    public static function minifyCacheTag(string $tag): ?string
    {
        return sprintf('%x', crc32($tag));
    }

    public static function addCacheTagsToResponse(array $tags, $duration = null): void
    {
        $tags = static::minifyCacheTags($tags);

        if ($tags->isNotEmpty()) {
            $response = Craft::$app->getResponse();
            $response->getHeaders()->set(HeaderEnum::CACHE_TAG->value, $tags->implode(','));

            // TODO: when would this be null?
            if ($duration !== null) {
                $response->getHeaders()->setDefault(
                    'Cache-Control',
                    "public, max-age=$duration",
                );
            }
        }
    }

    public static function addCachePurgeToResponse(PurgeModeEnum $mode): void
    {
        // You can purge up to 30 cache-tags per API call and up to 250,000 cache-tags per a 24-hour period.
        Craft::$app->getResponse()
            ->getHeaders()
            ->add(HeaderEnum::CACHE_PURGE->value, $mode->value);
    }

    public static function addCacheControlHeaders(?int $duration)
    {
        Craft::$app->getResponse()->getHeaders()->setDefault(
            'Cache-Control',
            "max-age=$duration",
        );
    }
}