<?php

namespace craft\cloud\runtime\event;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsRecord;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class SqsHandler extends \Bref\Event\Sqs\SqsHandler
{
    protected Context $context;

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        $this->context = $context;
        $records = Collection::make($event->getRecords());

        $records->each(function(SqsRecord $record) {
            $cliHandler = new CliHandler();
            $body = json_decode(
                $record->getBody(),
                associative: false,
                flags: JSON_THROW_ON_ERROR
            );
            $jobId = $body->jobId ?? null;

            if (!$jobId) {
                throw new \Exception('The SQS message does not contain a job ID.');
            }

            try {
                $cliHandler->handle([
                    'command' => "cloud/queue/exec {$jobId}",
                ], $this->context, true);
            } catch (ProcessFailedException $e) {
                echo $e->getMessage();

                $this->failJob(
                    $jobId,
                    $record,
                    $e->getMessage(),
                );
            } catch (ProcessTimedOutException $e) {
                if ($cliHandler->shouldRetry()) {
                    echo $e->getMessage();

                    $this->markAsFailed($record);
                    return;
                }

                echo $e->getMessage();

                $this->failJob(
                    $jobId,
                    $record,
                    'Job exceeded maximum running time: 15 minutes',
                );
            } catch (\Throwable $e) {
                echo $e->getMessage();
                $this->markAsFailed($record);
            }
        });
    }

    protected function failJob(string $jobId, SqsRecord $record , string $message = ''): void
    {
        try {
            (new CliHandler())->handle([
                'command' => "cloud/queue/fail {$jobId} --message={$message}",
            ], $this->context, true);
        } catch (\Throwable $e) {
            echo $e->getMessage();

            // Attempt to retry the whole message
            $this->markAsFailed($record);

            return;
        }
    }
}
