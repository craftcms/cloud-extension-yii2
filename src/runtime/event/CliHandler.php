<?php

namespace craft\cloud\runtime\event;

use Bref\Context\Context;
use Bref\Event\Handler;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use yii\base\Exception;

class CliHandler implements Handler
{
    public const MAX_EXECUTION_SECONDS = 900;
    public const MAX_EXECUTION_BUFFER_SECONDS = 3;
    public ?Process $process = null;
    protected string $scriptPath = '/var/task/craft';
    protected ?float $totalRunningTime = null;

    /**
     * @inheritDoc
     */
    public function handle(mixed $event, Context $context, $throw = false): array
    {
        $commandString = $event['command'] ?? null;

        if (!$commandString) {
            throw new \Exception('No command found.');
        }

        $remainingSeconds = $context->getRemainingTimeInMillis() / 1000;
        $timeout = max(1, $remainingSeconds - 1);
        $commandArgs = explode(' ', $commandString);
        $this->process = new Process(
            [PHP_BINARY, $this->scriptPath, ...$commandArgs],
            null,
            [
                'LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR),
            ],
            null,
            $timeout,
        );

        echo "Function time remaining: {$remainingSeconds} seconds";

        try {
            echo "Running command with $timeout second timeout: {$this->process->getCommandLine()}";

            /** @throws ProcessTimedOutException|ProcessFailedException */
            $this->process->mustRun(function($type, $buffer): void {
                echo $buffer;
            });

            echo "Command succeeded after {$this->getTotalRunningTime()} seconds: {$this->process->getCommandLine()}\n";
        } catch (\Throwable $e) {
            echo "Command failed after {$this->getTotalRunningTime()} seconds: {$this->process->getCommandLine()}\n";
            echo "Exception while handling CLI event:\n";
            echo "{$e->getMessage()}\n";
            echo "{$e->getTraceAsString()}\n";

            if ($throw) {
                throw $e;
            }
        }

        return [
            'exitCode' => $this->process->getExitCode(),
            'output' => $this->process->getErrorOutput() . $this->process->getOutput(),
            'runningTime' => $this->getTotalRunningTime(),
        ];
    }

    public function getTotalRunningTime(): float
    {
        if ($this->totalRunningTime !== null) {
            return $this->totalRunningTime;
        }

        if (!$this->process) {
            throw new Exception('Process does not exist');
        }

        return max(0, microtime(true) - $this->process->getStartTime());
    }

    public function shouldRetry(): bool
    {
        return $this->getTotalRunningTime() < static::maxExecutionSeconds();
    }

    public static function maxExecutionSeconds(): int
    {
        return static::MAX_EXECUTION_SECONDS - self::MAX_EXECUTION_BUFFER_SECONDS;
    }
}
