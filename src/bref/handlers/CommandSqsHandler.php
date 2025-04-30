<?php

namespace craft\cloud\bref\handlers;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use craft\cloud\bref\craft\CraftCliEntrypoint;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class CommandSqsHandler extends SqsHandler
{
    private CraftCliEntrypoint $entrypoint;

    public function __construct()
    {
        $this->entrypoint = new CraftCliEntrypoint();
    }

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        foreach ($event->getRecords() as $record) {
            $message = $record->getBody();

            $payload = json_decode($message, associative: true, flags: JSON_THROW_ON_ERROR);

            $callback = $payload['callback'] ?? throw new RuntimeException("Callback URL not found. Message: [$message]");

            $command = $payload['command'] ?? throw new RuntimeException('Command not found');

            $result = $this->runCommand($command, $context);

            $this->sendResultBack($callback, $result);
        }
    }

    public function runCommand(string $command, Context $context): array
    {
        try {
            $environment = ['LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR)];

            return $this->entrypoint->lambdaCommand($command, $environment);
        } catch (Throwable $t) {
            return [
                'exit_code' => 1,
                'output' => "Error running command [$command]: " . $t->getMessage(),
            ];
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
}
