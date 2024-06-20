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
    protected Response $response;

    public function __construct()
    {
        $this->response = Craft::$app->getResponse();
    }

    public function handle()
    {
        Event::on(
            Response::class,
            YiiResponse::EVENT_AFTER_PREPARE,
            [$this, 'afterPrepare'],
        );
    }

    public function gzip(): void
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

    public function afterPrepare(Event $event): void
    {
        $this->addDevModeHeader();
        $this->joinMultiValueHeaders();
        // $this->gzip();
        $this->serveBinaryFromS3();
    }

    /**
     * @throws ServerErrorHttpException
     */
    protected function serveBinaryFromS3(): void
    {
        if (!$this->response->stream) {
            return;
        }

        /** @var TmpFs $fs */
        $fs = Craft::createObject([
            'class' => TmpFs::class,
        ]);

        $stream = $this->response->stream[0] ?? null;

        if (!$stream) {
            throw new ServerErrorHttpException('Invalid stream in response.');
        }

        $path = uniqid('binary', true);

        // TODO: set expiry
        $fs->writeFileFromStream($path, $stream);

        // TODO: use \League\Flysystem\AwsS3V3\AwsS3V3Adapter::temporaryUrl?
        $cmd = $fs->getClient()->getCommand('GetObject', [
            'Bucket' => $fs->getBucketName(),
            'Key' => $fs->prefixPath($path),
            'ResponseContentDisposition' => $this->response->getHeaders()->get('content-disposition'),
        ]);

        // TODO: config
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

    /**
     * API Gateway v2, Cloudflare, and Bref all flatten multi-value headers into a CSV single string.
     * Rather than relying on this, we join them ourselves.
     *
     * @see https://developers.cloudflare.com/workers/runtime-apis/headers/#differences
     * @see https://docs.aws.amazon.com/apigateway/latest/developerguide/http-api-parameter-mapping.html
     * @see https://github.com/brefphp/bref/issues/1691
     */
    protected function joinMultiValueHeaders(string $glue = ','): void
    {
        Collection::make($this->response->getHeaders())
            ->reject(fn(array $values, string $name) => strcasecmp($name, 'Set-Cookie') === 0)
            ->each(function(array $values, string $name) use ($glue) {
                $this->joinHeaderValues($name, $values, $glue);
            });
    }

    protected function joinHeaderValues(string $name, array $values, string $glue): ?string
    {
        $value = Collection::make($values)
            ->filter()
            ->join($glue);

        $this->response->getHeaders()->set($name, $value);

        return $value;
    }

    protected function addDevModeHeader(): void
    {
        if (Module::getInstance()->getConfig()->getDevMode()) {
            $this->response->getHeaders()->set(HeaderEnum::DEV_MODE->value, '1');
        }
    }
}
