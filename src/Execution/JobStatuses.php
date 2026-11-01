<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Support\Duration;
use Throwable;

/**
 * What became of a job an agent queued.
 *
 * A queued job tool answers "accepted", which is honest and leaves the agent
 * with nothing to ask next. Each dispatch is given an id and a record that
 * the job's own middleware moves through queued, running, done and failed,
 * and the `job_status` tool reads it back for the actor who queued it.
 *
 * In the cache rather than a table: the question is asked within minutes of
 * the dispatch, the record is worth nothing once the agent has its answer,
 * and a table would need pruning for rows nobody reads twice.
 *
 * @phpstan-type JobRecord array{status: string, tool: string, job: string, actor_type: string, actor_id: string, queued_at: string, started_at: string|null, finished_at: string|null, error: string|null}
 */
class JobStatuses
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected const PREFIX = 'mcp-bridge:job:';

    public function __construct(
        protected Cache $cache,
        protected Repository $config,
    ) {}

    /**
     * Start a record for a job about to be dispatched, and return its id.
     *
     * @param  class-string  $class
     */
    public function queued(ToolCall $call, string $class): string
    {
        $id = (string) Str::uuid();
        $actor = $call->actor->getAuthIdentifier();

        $this->write($id, [
            'status' => self::QUEUED,
            'tool' => $call->definition->name,
            'job' => class_basename($class),
            'actor_type' => $call->actor::class,
            'actor_id' => is_scalar($actor) ? (string) $actor : '',
            'queued_at' => Date::now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'error' => null,
        ]);

        return $id;
    }

    public function mark(string $id, string $status, ?string $error = null): void
    {
        $record = $this->find($id);

        if ($record === null) {
            return;
        }

        $now = Date::now()->toIso8601String();

        $record['status'] = $status;
        $record['error'] = $error;

        if ($status === self::RUNNING) {
            $record['started_at'] = $now;
        }

        if ($status === self::DONE || $status === self::FAILED) {
            $record['finished_at'] = $now;
        }

        $this->write($id, $record);
    }

    /** @return JobRecord|null */
    public function find(string $id): ?array
    {
        try {
            $record = $this->cache->get(self::PREFIX.$id);
        } catch (Throwable) {
            return null;
        }

        /** @var JobRecord|null */
        return is_array($record) ? $record : null;
    }

    /**
     * Whether this actor queued the job. The same answer for an id that
     * does not exist and one that is somebody else's, so an agent cannot
     * tell the two apart by probing.
     *
     * @param  JobRecord  $record
     */
    public function belongsTo(array $record, Authenticatable $actor): bool
    {
        $identifier = $actor->getAuthIdentifier();

        return $record['actor_type'] === $actor::class
            && is_scalar($identifier)
            && $record['actor_id'] === (string) $identifier;
    }

    /** @param JobRecord $record */
    protected function write(string $id, array $record): void
    {
        try {
            $this->cache->put(self::PREFIX.$id, $record, $this->ttl());
        } catch (Throwable) {
            // Tracking is a courtesy. A cache that cannot hold the record
            // leaves the agent without a status, never without its job.
        }
    }

    protected function ttl(): int
    {
        return Duration::seconds($this->config->get('mcp-bridge.sources.job.status_ttl'), 86400);
    }
}
