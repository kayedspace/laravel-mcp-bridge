<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * The four MCP tool annotations. Always explicit: clients read a missing hint
 * as its worst case, so inference resolves to a real boolean at compile time.
 *
 * @phpstan-type SafetyArray array{read_only: bool, destructive: bool, idempotent: bool, open_world: bool}
 */
final readonly class Safety
{
    public function __construct(
        public bool $readOnly,
        public bool $destructive,
        public bool $idempotent,
        public bool $openWorld,
    ) {}

    public static function readOnly(): self
    {
        return new self(readOnly: true, destructive: false, idempotent: true, openWorld: false);
    }

    public static function destructive(): self
    {
        return new self(readOnly: false, destructive: true, idempotent: false, openWorld: false);
    }

    /**
     * What an HTTP verb says about the work, which is the inference a route
     * and an OpenAPI operation share. Overridden later by any declaration.
     */
    public static function fromHttpMethod(string $method): self
    {
        return match (strtoupper($method)) {
            'GET' => new self(readOnly: true, destructive: false, idempotent: true, openWorld: false),
            'PUT' => new self(readOnly: false, destructive: false, idempotent: true, openWorld: false),
            'DELETE' => new self(readOnly: false, destructive: true, idempotent: true, openWorld: false),
            default => new self(readOnly: false, destructive: false, idempotent: false, openWorld: false),
        };
    }

    /**
     * Apply explicit overrides over inferred values, so a declaration always
     * beats a guess made from the HTTP method or from the source's default.
     *
     * Read-only and destructive are contradictory, so declaring one settles
     * the other unless that one was declared too. This matters most for
     * commands, which start destructive by default: `#[IsReadOnly]` on one
     * has to mean "and therefore not destructive", or a command that only
     * prints would still ask for confirmation.
     *
     * @param  array<string, bool>  $overrides  keyed read_only, destructive, idempotent, open_world
     */
    public function with(array $overrides): self
    {
        $readOnly = $overrides['read_only'] ?? $this->readOnly;
        $destructive = $overrides['destructive'] ?? $this->destructive;

        if ($readOnly && ! array_key_exists('destructive', $overrides)) {
            $destructive = false;
        }

        if ($destructive && ! array_key_exists('read_only', $overrides)) {
            $readOnly = false;
        }

        return new self(
            readOnly: $readOnly,
            destructive: $destructive,
            idempotent: $overrides['idempotent'] ?? $this->idempotent,
            openWorld: $overrides['open_world'] ?? $this->openWorld,
        );
    }

    /** @return SafetyArray */
    public function toArray(): array
    {
        return [
            'read_only' => $this->readOnly,
            'destructive' => $this->destructive,
            'idempotent' => $this->idempotent,
            'open_world' => $this->openWorld,
        ];
    }

    /** @param SafetyArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            readOnly: $data['read_only'],
            destructive: $data['destructive'],
            idempotent: $data['idempotent'],
            openWorld: $data['open_world'],
        );
    }
}
