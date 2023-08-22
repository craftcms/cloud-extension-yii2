<?php

namespace craft\cloud\queue;

use Craft;
use craft\cloud\Module;
use samdark\log\PsrMessage;
use yii\base\NotSupportedException;

class Queue extends \yii\queue\sqs\Queue
{
    public function __construct($config = [])
    {
        $config += [
            'url' => Module::getInstance()->getConfig()->sqsUrl,
            'region' => Module::getInstance()->getConfig()->getRegion(),
            'serializer' => Serializer::class,
        ];

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        if ($priority) {
            throw new NotSupportedException('Priority is not supported in this driver');
        }

        $request = [
            'QueueUrl' => $this->url,
            'MessageBody' => $message,
            'DelaySeconds' => $delay,
            'MessageAttributes' => [
                'TTR' => [
                    'DataType' => 'Number',
                    'StringValue' => $ttr,
                ],
            ],
        ];

        if (substr($this->url, -5) === '.fifo') {
            $request['MessageGroupId'] = $this->messageGroupId;
            $request['MessageDeduplicationId'] = hash('sha256', $message);
        }

        Craft::info(new PsrMessage(
            'SQS request',
            $request,
        ));

        try {
            $response = $this->getClient()->sendMessage($request);
        } catch(\Throwable $e) {
            Craft::info(new PsrMessage(
                'SQS exception',
                [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTrace(),
                ],
            ));
        }

        Craft::info(new PsrMessage(
            'SQS response',
            $response->toArray(),
        ));

        return $response['MessageId'];
    }
}
