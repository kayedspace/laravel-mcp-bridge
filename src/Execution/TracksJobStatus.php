<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Closure;
use Throwable;

/**
 * Job middleware that moves the job's record through running, done and
 * failed, so `job_status` has something to say.
 *
 * Attached outermost, before the re-authorization check, so a job refused
 * in the worker is recorded as failed with the reason rather than left
 * "queued" for as long as the record lives.
 *
 * The exception is rethrown after being noted: what the queue does with a
 * failed job (retry, mark it failed, run `failed()`) is the queue's business
 * and nothing here changes it.
 */
final readonly class TracksJobStatus
{
    public function __construct(public string $id) {}

    public function handle(object $job, Closure $next): void
    {
        $statuses = app(JobStatuses::class);

        $statuses->mark($this->id, JobStatuses::RUNNING);

        try {
            $next($job);
        } catch (Throwable $exception) {
            $statuses->mark($this->id, JobStatuses::FAILED, $exception->getMessage());

            throw $exception;
        }

        $statuses->mark($this->id, JobStatuses::DONE);
    }
}
