<?php

namespace craft\cloud\bref\handlers;

use Bref\Context\Context;
use Bref\Event\Handler;
use craft\cloud\bref\craft\CraftEntrypoint;
use InvalidArgumentException;

/**
 * @internal
 */
final class CraftCommandHandler implements Handler
{
    /**
     * @inheritDoc
     */
    public function handle(mixed $event, Context $context): array
    {
        if (!isset($event['command'])) {
            throw new InvalidArgumentException('No command found.');
        }

        $command = $event['command'];

        $environment = ['LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR)];

        $entrypoint = new CraftEntrypoint();

        return $entrypoint->command($command, $environment, 890);
    }
}
