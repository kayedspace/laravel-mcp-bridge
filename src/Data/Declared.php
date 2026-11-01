<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * What a candidate's attributes asked for, as plain data.
 *
 * Attribute objects stay in the discovery layer; this is the serialisable
 * form the compiler reads, so a candidate can still round-trip through an
 * array and the compiler never reflects over user code.
 *
 * @phpstan-type ExampleArray array{input: array<string, mixed>, description: string|null}
 *
 * @phpstan-import-type QueuedArray from Queued
 *
 * @phpstan-type ParamArray array{name: string, description: string|null, example: mixed, enum: list<string>, type: string|null, hidden: bool, from_auth: bool|null}
 * @phpstan-type DeclaredArray array{scopes: list<string>, abilities: list<string>, confirm: bool, confirm_message: string|null, rate_limit: string|null, timeout: int|null, deprecated_message: string|null, deprecated_until: string|null, output_fields: list<string>, output_max_items: int|null, examples: list<ExampleArray>, safety: array<string, bool>, queue: QueuedArray|null, params: list<ParamArray>}
 */
final readonly class Declared
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $abilities
     * @param  list<string>  $outputFields
     * @param  list<array{input: array<string, mixed>, description: string|null}>  $examples
     * @param  array<string, bool>  $safety  explicit overrides, keyed read_only, destructive, idempotent, open_world
     * @param  list<ParamArray>  $params  every #[McpParam], as data, so the compiler can shape a field on any source
     */
    public function __construct(
        public array $scopes = [],
        public array $abilities = [],
        public bool $confirm = false,
        public ?string $confirmMessage = null,
        public ?string $rateLimit = null,
        public ?int $timeout = null,
        public ?string $deprecatedMessage = null,
        public ?string $deprecatedUntil = null,
        public array $outputFields = [],
        public ?int $outputMaxItems = null,
        public array $examples = [],
        public array $safety = [],
        public ?Queued $queue = null,
        public array $params = [],
    ) {}

    public function isDeprecated(): bool
    {
        return $this->deprecatedMessage !== null || $this->deprecatedUntil !== null;
    }

    /** @return DeclaredArray */
    public function toArray(): array
    {
        return [
            'scopes' => $this->scopes,
            'abilities' => $this->abilities,
            'confirm' => $this->confirm,
            'confirm_message' => $this->confirmMessage,
            'rate_limit' => $this->rateLimit,
            'timeout' => $this->timeout,
            'deprecated_message' => $this->deprecatedMessage,
            'deprecated_until' => $this->deprecatedUntil,
            'output_fields' => $this->outputFields,
            'output_max_items' => $this->outputMaxItems,
            'examples' => $this->examples,
            'safety' => $this->safety,
            'queue' => $this->queue?->toArray(),
            'params' => $this->params,
        ];
    }

    /** @param DeclaredArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            scopes: $data['scopes'],
            abilities: $data['abilities'],
            confirm: $data['confirm'],
            confirmMessage: $data['confirm_message'],
            rateLimit: $data['rate_limit'],
            timeout: $data['timeout'],
            deprecatedMessage: $data['deprecated_message'],
            deprecatedUntil: $data['deprecated_until'],
            outputFields: $data['output_fields'],
            outputMaxItems: $data['output_max_items'],
            examples: $data['examples'],
            safety: $data['safety'],
            queue: $data['queue'] === null ? null : Queued::fromArray($data['queue']),
            params: $data['params'],
        );
    }
}
