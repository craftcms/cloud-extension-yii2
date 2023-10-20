<?php

namespace craft\cloud;

use Craft;
use craft\cloud\fs\TmpFs;
use craft\errors\FsException;
use craft\web\Response;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\web\Response as YiiResponse;
use yii\web\ServerErrorHttpException;

/**
 * @property Response $owner
 */
class ResponseBehavior extends Behavior
{
    public function events()
    {
        return [
            YiiResponse::EVENT_BEFORE_SEND => 'beforeSend',
        ];
    }

    public function beforeSend(): void
    {
        if ($this->owner->stream) {
            $this->serveBinaryFromS3();
        }
    }

    /**
     * @throws InvalidConfigException
     * @throws ServerErrorHttpException
     * @throws FsException
     */
    protected function serveBinaryFromS3(): void
    {
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
}