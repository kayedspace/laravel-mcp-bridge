<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Server;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Contracts\StoresAudit;
use KayedSpace\McpBridge\Data\AuditEntry;
use KayedSpace\McpBridge\Enums\Outcome;
use KayedSpace\McpBridge\Execution\JobStatuses;
use KayedSpace\McpBridge\Security\ActorResolver;
use KayedSpace\McpBridge\Server\Concerns\NamesClient;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * The second half of a queued job: what became of it.
 *
 * On every server that carries a job tool, because a tool that answers
 * "accepted" owes the agent a way to ask what happened next. Answered only
 * to the actor who queued the job, and an id that is not theirs reads the
 * same as one that never existed.
 */
class JobStatusTool extends Tool
{
    use NamesClient;

    public function __construct(
        protected JobStatuses $statuses,
        protected ActorResolver $actors,
        protected StoresAudit $audit,
        protected ServerKeyResolver $keys,
        protected Repository $config,
    ) {}

    public function name(): string
    {
        return 'job_status';
    }

    public function title(): string
    {
        return 'What became of a queued job';
    }

    public function description(): string
    {
        return 'Whether a job you queued is still waiting, running, done or failed. '
            .'Pass the id a job tool returned. Read-only.';
    }

    /** @return array<string, mixed> */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The id a job tool returned when it queued the job.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $actor = $this->actors->resolve($request->user());

        if ($actor === null) {
            return Response::error(
                $this->actors->problem() ?? 'Not authenticated. The connection needs to be re-authorised.',
            );
        }

        $id = $request->get('id');

        if (! is_string($id) || $id === '') {
            return Response::error('Pass the id a job tool returned.');
        }

        $record = $this->statuses->find($id);

        if ($record === null || ! $this->statuses->belongsTo($record, $actor)) {
            $this->record($id, $actor, $request, Outcome::Refused);

            return Response::error('No job with that id was queued by you. Do not retry.');
        }

        $this->record($id, $actor, $request, Outcome::Allowed);

        $structured = [
            'id' => $id,
            'status' => $record['status'],
            'job' => $record['job'],
            'tool' => $record['tool'],
            'queued_at' => $record['queued_at'],
            'started_at' => $record['started_at'],
            'finished_at' => $record['finished_at'],
            // The failure's message names the application's internals, so
            // it is shown on the same terms as a 5xx detail.
            'error' => $this->config->boolean('mcp-bridge.security.expose_error_details', false)
                ? $record['error']
                : null,
        ];

        $text = "The {$record['job']} job queued by {$record['tool']} is {$record['status']}.";

        if ($record['status'] === JobStatuses::FAILED && $structured['error'] === null) {
            $text .= ' The application log has the detail.';
        }

        return Response::make(Response::text($text."\n".json_encode($structured, JSON_UNESCAPED_SLASHES)))
            ->withStructuredContent($structured);
    }

    /**
     * In the trail like any other call, so "what did this agent do" includes
     * what it asked about. Added to the server rather than compiled into the
     * manifest, so it writes its own entry, as the upload tool does.
     */
    protected function record(string $id, Authenticatable $actor, Request $request, Outcome $outcome): void
    {
        $identifier = $actor->getAuthIdentifier();

        $this->audit->record(new AuditEntry(
            tool: $this->name(),
            source: 'job',
            server: $this->keys->current(),
            actorType: $actor::class,
            actorId: is_scalar($identifier) ? (string) $identifier : null,
            client: $this->clientName($request),
            requestId: (string) Str::uuid(),
            arguments: ['id' => $id],
            outcome: $outcome,
            errorCode: $outcome === Outcome::Refused ? 'MCP_BRIDGE_NOT_FOUND' : null,
        ));
    }
}
