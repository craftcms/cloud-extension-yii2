<?php

namespace craft\cloud\web;

use Craft;
use craft\cloud\fs\TmpFs;
use craft\cloud\HeaderEnum;
use craft\cloud\Module;
use craft\web\Response;
use Illuminate\Support\Collection;
use yii\base\Behavior;
use yii\base\Event;
use yii\web\Response as YiiResponse;
use yii\web\ServerErrorHttpException;

/**
 * @property Response $owner
 */
class ResponseBehavior extends Behavior
{
    public function events(): array
    {
        return [
            YiiResponse::EVENT_AFTER_PREPARE => [$this, 'afterPrepare'],
        ];
    }

    public function gzip(): void
    {
        $accepts = preg_split(
            '/\s*\,\s*/',
            Craft::$app->getRequest()->getHeaders()->get('Accept-Encoding') ?? ''
        );

        if (Collection::make($accepts)->doesntContain('gzip')) {
            return;
        }

        $this->owner->content = gzencode($this->owner->content, 9);
        $this->owner->getHeaders()->set('Content-Encoding', 'gzip');
    }

    public function afterPrepare(Event $event): void
    {
        $this->addDevModeHeader();
        $this->joinMultiValueHeaders();
        $this->gzip();
        $this->serveBinaryFromS3();
    }

    /**
     * @throws ServerErrorHttpException
     */
    protected function serveBinaryFromS3(): void
    {
        if (!$this->owner->stream) {
            return;
        }

        /** @var TmpFs $fs */
        $fs = Craft::createObject([
            'class' => TmpFs::class,
        ]);

        $stream = $this->owner->stream[0] ?? null;

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
            'ResponseContentDisposition' => $this->owner->getHeaders()->get('content-disposition'),
        ]);

        // TODO: config
        $s3Request = $fs->getClient()->createPresignedRequest($cmd, '+20 minutes');
        $url = (string) $s3Request->getUri();
        $this->owner->clear();
        $this->owner->redirect($url);
    }

    /**
     * API Gateway v2 doesn't support multi-value headers,
     * and Bref currently will truncate all but the last value.
     *
     * @see https://github.com/brefphp/bref/issues/1691
     * @see https://developers.cloudflare.com/workers/runtime-apis/headers/#differences
     * @see https://github.com/brefphp/bref/issues/1691
     */
    protected function joinMultiValueHeaders(string $glue = ','): void
    {
        Collection::make($this->owner->getHeaders())
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

        $this->owner->getHeaders()->set($name, $value);

        return $value;
    }

    protected function addDevModeHeader(): void
    {
        if (Module::getInstance()->getConfig()->getDevMode()) {
            $this->owner->getHeaders()->set(HeaderEnum::DEV_MODE->value, '1');
        }
    }
}
