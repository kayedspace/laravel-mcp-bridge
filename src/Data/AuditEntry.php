<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

use KayedSpace\McpBridge\Enums\Outcome;

/**
 * One line in the record of what an agent did.
 *
 * Written for every call, including the ones that were refused: "this agent
 * tried to delete a course and was not allowed" is the entry that matters
 * most, and a log that only holds successes cannot answer what happened.
 *
 * @phpstan-type AuditEntryArray array{tool: string, source: string, server: string, actor_type: string|null, actor_id: string|null, token_type: string|null, token_id: string|null, client: string|null, request_id: string, arguments: array<string, mixed>, outcome: string, error_code: string|null, status: int|null, duration_ms: int, confirmed: bool}
 */
final readonly class AuditEntry
{
    /**
     * @param  string|null  $tokenId  which of the actor's tokens made the call, since one person's Claude and n8n tokens look alike by actor
     * @param  string|null  $tokenType  the token's morph class, since ids from two token tables can be equal
     * @param  array<string, mixed>  $arguments  already redacted
     */
    public function __construct(
        public string $tool,
        public string $source,
        public string $server,
        public ?string $actorType,
        public ?string $actorId,
        public ?string $client,
        public string $requestId,
        public array $arguments,
        public Outcome $outcome,
        public ?string $errorCode = null,
        public ?int $status = null,
        public int $durationMs = 0,
        public bool $confirmed = false,
        public ?string $tokenId = null,
        public ?string $tokenType = null,
    ) {}

    /** @return AuditEntryArray */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'source' => $this->source,
            'server' => $this->server,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'token_type' => $this->tokenType,
            'token_id' => $this->tokenId,
            'client' => $this->client,
            'request_id' => $this->requestId,
            'arguments' => $this->arguments,
            'outcome' => $this->outcome->value,
            'error_code' => $this->errorCode,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'confirmed' => $this->confirmed,
        ];
    }
}
