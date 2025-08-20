<?php

namespace craft\cloud\bref\craft;

use craft\cloud\AppConfig;
use craft\cloud\queue\SqsQueue;
use craft\cloud\runtime\event\EventHandler;
use Symfony\Component\Process\Process;

final class CraftCliEntrypoint
{
    /**
     * We leave a 10-second buffer for Lambda to start up and shutdown gracefully.
     *
     * TODO: use this value to set max_execution_time, queue ttr:
     * - @see EventHandler::MAX_SECONDS, EventHandler::MAX_HTTP_SECONDS
     * - @see SqsQueue::ttr(), AppConfig::getQueue()
     */
    private const LAMBDA_EXECUTION_LIMIT = 890;

    private function command(string $command, array $environment, int $timeout): array
    {
        $php = PHP_BINARY;

        $shellCommand = escapeshellcmd("$php /var/task/craft $command");

        $process = Process::fromShellCommandline($shellCommand, null, $environment, null, $timeout);

        $process->run(function($type, $buffer): void {
            echo $buffer;
        });

        return [
            'exit_code' => $process->getExitCode(),
            'output' => $process->getErrorOutput() . $process->getOutput(),
            'duration' => microtime(true) - $process->getStartTime(),
        ];
    }

    public function lambdaCommand(string $command, array $environment)
    {
        return $this->command($command, $environment, self::LAMBDA_EXECUTION_LIMIT);
    }
}
