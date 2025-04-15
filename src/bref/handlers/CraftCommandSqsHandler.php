<?php

namespace craft\cloud\bref\handlers;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Craft;
use craft\cloud\bref\craft\CraftEntrypoint;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use yii\base\Exception;

final class CraftCommandSqsHandler extends SqsHandler
{
    public function handleSqs(SqsEvent $event, Context $context): void
    {
        $entrypoint = new CraftEntrypoint();

        foreach ($event->getRecords() as $record) {
            $record->getBody();

            $body = json_decode($record->getBody(), flags: JSON_THROW_ON_ERROR);

            $command = $body['command'] ?? throw new RuntimeException('Command not found');

            $callback = $body['callback'] ?? throw new RuntimeException('Callback URL not found');

            $environment = ['LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR)];

            $result = $entrypoint->command($command, $environment, 890);

            $this->sendResultBack($callback, $result);
        }
    }

    private function sendResultBack(string $url, array $body): void
    {
        $client = Craft::createGuzzleClient();

        $client->post($url, ['body' => json_encode($body)]);
    }
}
