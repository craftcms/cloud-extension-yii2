<?php

namespace craft\cloud\web;

use Craft;
use craft\cloud\fs\TmpFs;
use craft\cloud\HeaderEnum;
use craft\cloud\Module;
use craft\web\Response;
use Illuminate\Support\Collection;
use yii\base\Event;
use yii\web\Response as YiiResponse;
use yii\web\ServerErrorHttpException;

class ResponseEventHandler
{
    private Response $response;

    public function __construct()
    {
        $this->response = Craft::$app->getResponse();
    }

    public function handle(): void
    {
        Event::on(
            Response::class,
            YiiResponse::EVENT_AFTER_PREPARE,
            fn(Event $event) => $this->afterPrepare($event),
        );
    }

    private function afterPrepare(Event $event): void
    {
        if (Module::getInstance()->getConfig()->getDevMode()) {
            $this->addDevModeHeader();
        }

        $this->normalizeHeaders();

        if (Module::getInstance()->getConfig()->gzipResponse) {
            $this->gzipResponse();
        }

        if ($this->response->stream && $this->response->getContentType() !== 'text/event-stream') {
            $this->serveBinaryFromS3();
        }
    }

    private function gzipResponse(): void
    {
        $accepts = preg_split(
            '/\s*\,\s*/',
            Craft::$app->getRequest()->getHeaders()->get('Accept-Encoding') ?? ''
        );

        if (Collection::make($accepts)->doesntContain('gzip') || $this->response->content === null) {
            return;
        }

        $this->response->content = gzencode($this->response->content, 9);
        $this->response->getHeaders()->set('Content-Encoding', 'gzip');
    }

    /**
     * @throws ServerErrorHttpException
     */
    private function serveBinaryFromS3(): void
    {
        $stream = $this->response->stream[0] ?? null;

        if (!$stream) {
            throw new ServerErrorHttpException('Invalid stream in response.');
        }

        $path = uniqid('binary', true);

        /** @var TmpFs $fs */
        $fs = Craft::createObject([
            'class' => TmpFs::class,
        ]);

        // TODO: set expiry
        $fs->writeFileFromStream($path, $stream);

        // TODO: use \League\Flysystem\AwsS3V3\AwsS3V3Adapter::temporaryUrl?
        $cmd = $fs->getClient()->getCommand('GetObject', [
            'Bucket' => $fs->getBucketName(),
            'Key' => $fs->prefixPath($path),
            'ResponseContentDisposition' => $this->response->getHeaders()->get('content-disposition'),
        ]);

        // TODO: expiry config
        $s3Request = $fs->getClient()->createPresignedRequest($cmd, '+20 minutes');
        $url = (string) $s3Request->getUri();

        // Clear response so stream is reset and we don't recursively call this method.
        $this->response->clear();

        // Don't cache the redirect, as its validity is short-lived.
        $this->response->setNoCacheHeaders();

        $this->response->redirect($url);

        // Ensure we don't recursively call send()
        // @see https://github.com/craftcms/cms/pull/15014
        Craft::$app->end();
    }

    private function normalizeHeaders(): void
    {
        Collection::make($this->response->getHeaders())
            ->each(function(array $values, string $name) {
                if (HeaderEnum::SET_COOKIE->matches($name)) {
                    return;
                }

                $value = $this->joinHeaderValues($values);

                // Header value can't exceed 16KB
                // https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
                if (HeaderEnum::CACHE_TAG->matches($name)) {
                    $value = $this->limitHeaderToBytes($value, 16 * 1024);
                }

                $this->response->getHeaders()->set($name, $value);
            });
    }

    /**
     * API Gateway v2, Cloudflare, and Bref all flatten multi-value headers into a CSV single string.
     * Rather than relying on this, we join them ourselves.
     *
     * @see https://developers.cloudflare.com/workers/runtime-apis/headers/#differences
     * @see https://docs.aws.amazon.com/apigateway/latest/developerguide/http-api-parameter-mapping.html
     * @see https://github.com/brefphp/bref/issues/1691
     */
    private function joinHeaderValues(array $values, string $glue = ','): string
    {
        return Collection::make($values)
            ->filter()
            ->join($glue);
    }

    private function addDevModeHeader(): void
    {
        $this->response->getHeaders()->set(HeaderEnum::DEV_MODE->value, '1');
    }

    private function limitHeaderToBytes(string $value, int $bytes, ?string $glue = ','): string
    {
        $truncated = substr($value, 0, $bytes);

        if (!$glue) {
            return $truncated;
        }

        $length = strrpos($truncated, $glue);

        return $length === false
            ? $truncated
            : substr($truncated, 0, $length);
    }
}
