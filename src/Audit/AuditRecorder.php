<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Audit;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Contracts\StoresAudit;
use KayedSpace\McpBridge\Data\AuditEntry;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Enums\Outcome;

/**
 * Turns a finished call into an audit entry.
 *
 * One recorder for every source, which is the point: a team asking what an
 * agent did should not have to know whether it did it through a route, a
 * command or a job.
 */
class AuditRecorder
{
    public function __construct(
        protected StoresAudit $store,
        protected Redactor $redactor,
        protected Repository $config,
    ) {}

    public function record(ToolCall $call, ToolResult $result, int $durationMs, Outcome $outcome, ?int $status = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $actor = $call->actor;
        $id = $actor->getAuthIdentifier();

        $this->store->record(new AuditEntry(
            tool: $call->definition->name,
            source: $call->definition->source,
            server: $call->definition->server,
            actorType: $actor::class,
            actorId: is_scalar($id) ? (string) $id : null,
            client: $call->clientName,
            requestId: $call->requestId,
            arguments: $this->redactor->mask($call->arguments),
            outcome: $outcome,
            errorCode: $result->errorCode,
            status: $status,
            durationMs: $durationMs,
            confirmed: $call->confirm !== null,
            tokenId: $this->tokenKey($token = $this->token($actor)),
            tokenType: $token?->getMorphClass(),
        ));
    }

    /**
     * The package's own token when the call came with one, else a Sanctum
     * access token, else nothing: a session user has no token to name. A
     * model can carry both traits, so an absent package token falls through
     * to Sanctum rather than ending the search.
     */
    protected function token(Authenticatable $actor): ?Model
    {
        $mcp = method_exists($actor, 'currentMcpToken') ? $actor->currentMcpToken() : null;

        if ($mcp instanceof Model) {
            return $mcp;
        }

        $sanctum = method_exists($actor, 'currentAccessToken') ? $actor->currentAccessToken() : null;

        return $sanctum instanceof Model ? $sanctum : null;
    }

    protected function tokenKey(?Model $token): ?string
    {
        $key = $token?->getKey();

        return is_scalar($key) ? (string) $key : null;
    }

    protected function enabled(): bool
    {
        return $this->config->string('mcp-bridge.audit.driver', 'database') !== 'none';
    }
}
