<?php

namespace craft\cloud\queue;

use Craft;

class SqsQueue extends \yii\queue\sqs\Queue
{
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        /**
         * Delay pushing to SQS until after request is processed.
         *
         * Without this, a job might be pushed to SQS from within a DB
         * transaction and send back to Craft for handling before the
         * transaction ends. At this point, the job ID doesn't exist,
         * so the craft cloud/queue/exec command will fail and SQS
         * will consider the job processed. Once the transaction ends,
         * the job will exist and be indefinitely pending.
         */
        Craft::$app->getDb()->onAfterTransaction(function() use ($message, $ttr, $delay) {
            return parent::pushMessage(
                $message,
                $ttr,
                $delay,

                // Priority is not supported by SQS
                null,
            );
        });

        // Return anything but null, as we don't have an SQS message ID yet.
        return '';
    }
}
