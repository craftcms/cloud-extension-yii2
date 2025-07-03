<?php

namespace craft\cloud\runtime\event;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\FpmRuntime\FpmHandler;

class EventHandler implements Handler
{
    private FpmHandler $fpmHandler;

    # include buffer, so PHP dies before Lambda
    private const MAX_SECONDS = 900 - 3;

    private const MAX_HTTP_SECONDS = 60;

    public function __construct(FpmHandler $fpmHandler)
    {
        $this->fpmHandler = $fpmHandler;
    }

    public function handle(mixed $event, Context $context)
    {
        ini_set('max_execution_time', self::MAX_SECONDS);

        // is this a sqs event?
        if (isset($event['Records'])) {
            return (new SqsHandler())->handle($event, $context);
        }

        // is this a craft command event?
        if (isset($event['command'])) {
            return (new CliHandler())->handle($event, $context);
        }

        ini_set('max_execution_time', self::MAX_HTTP_SECONDS);

        return $this->fpmHandler->handle($event, $context);
    }
}
