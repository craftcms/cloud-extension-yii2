<?php

namespace craft\cloud\bref\handlers;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Bref\Event\Sqs\SqsRecord;
use craft\cloud\bref\craft\CraftCliEntrypoint;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class CraftCommandSqsHandler extends SqsHandler
{
    private CraftCliEntrypoint $entrypoint;

    public function __construct()
    {
        $this->entrypoint = new CraftCliEntrypoint();
    }

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        foreach ($event->getRecords() as $record) {
            $callback = $body['callback'] ?? throw new RuntimeException('Callback URL not found');

            $result = $this->runCommand($record, $context);

            $this->sendResultBack($callback, $result);
        }
    }

    private function sendResultBack(string $url, array $body): void
    {
        $project = getenv('CRAFT_CLOUD_PROJECT_ID');

        $environment = getenv('CRAFT_CLOUD_ENVIRONMENT_ID');

        $body = json_encode($body);

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            "User-Agent: Craft/Cloud/$project/$environment",
        ]);

        curl_exec($ch);

        curl_close($ch);
    }

    public function runCommand(SqsRecord $record, Context $context): array
    {
        try {
            $record->getBody();

            $body = json_decode($record->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

            $command = $body['command'] ?? throw new RuntimeException('Command not found');

            $environment = ['LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR)];

            return $this->entrypoint->lambdaCommand($command, $environment);
        } catch (Throwable $t) {
            if (! isset($command)) {
                return [
                    'exit_code' => 1,
                    'output' => 'Internal Error: command is undefined',
                ];
            }

            return [
                'exit_code' => 1,
                'output' => "Error running command [$command]: " . $t->getMessage(),
            ];
        }
    }
}
