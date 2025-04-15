<?php

namespace craft\cloud\bref\craft;

use Bref\Context\Context;
use Bref\Event\Handler;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use yii\base\Exception;

final class CraftEntrypoint
{
    public function command(string $command, array $environment, int $timeout): array
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
}
