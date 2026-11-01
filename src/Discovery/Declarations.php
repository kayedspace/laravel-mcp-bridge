<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Discovery;

use KayedSpace\McpBridge\Attributes\McpConfirm;
use KayedSpace\McpBridge\Attributes\McpDeprecated;
use KayedSpace\McpBridge\Attributes\McpExample;
use KayedSpace\McpBridge\Attributes\McpInput;
use KayedSpace\McpBridge\Attributes\McpOutput;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Attributes\McpQueue;
use KayedSpace\McpBridge\Attributes\McpRateLimit;
use KayedSpace\McpBridge\Attributes\McpTimeout;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Data\Declared;
use KayedSpace\McpBridge\Data\Queued;

/**
 * What the attributes on a class and method add up to.
 *
 * Single-valued declarations take the method's version over the class's;
 * repeatable ones collect from both, class first.
 */
final readonly class Declarations
{
    /**
     * @param  list<McpParam>  $params
     * @param  list<McpExample>  $examples
     * @param  list<string>  $scopes
     * @param  list<string>  $abilities
     * @param  list<string>  $groups
     * @param  array<string, bool>  $safety  explicit overrides, keyed read_only, destructive, idempotent, open_world
     * @param  array<string, array<array-key, mixed>>  $raw  every attribute found, keyed by class name
     */
    public function __construct(
        public ?McpTool $tool = null,
        public bool $ignored = false,
        public ?McpInput $input = null,
        public ?McpOutput $output = null,
        public ?McpConfirm $confirm = null,
        public ?McpRateLimit $rateLimit = null,
        public ?McpTimeout $timeout = null,
        public ?McpQueue $queue = null,
        public ?McpDeprecated $deprecated = null,
        public array $params = [],
        public array $examples = [],
        public array $scopes = [],
        public array $abilities = [],
        public array $groups = [],
        public array $safety = [],
        public array $raw = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function optedIn(): bool
    {
        return $this->tool !== null;
    }

    /** The serialisable form a candidate carries into the compiler. */
    public function toDeclared(): Declared
    {
        return new Declared(
            scopes: $this->scopes,
            abilities: $this->abilities,
            confirm: $this->confirm !== null,
            confirmMessage: $this->confirm?->message,
            rateLimit: $this->rateLimit?->toLimit(),
            timeout: $this->timeout?->seconds,
            deprecatedMessage: $this->deprecated?->message,
            deprecatedUntil: $this->deprecated?->until,
            outputFields: $this->output->fields ?? [],
            outputMaxItems: $this->output?->maxItems,
            examples: array_map(
                fn (McpExample $example): array => [
                    'input' => $example->input,
                    'description' => $example->description,
                ],
                $this->examples,
            ),
            safety: $this->safety,
            queue: $this->queue === null ? null : new Queued(
                queue: $this->queue->queue,
                connection: $this->queue->connection,
                sync: $this->queue->sync,
                delay: $this->queue->delay,
            ),
            params: array_map(
                fn (McpParam $param): array => [
                    'name' => $param->name,
                    'description' => $param->description,
                    'example' => $param->example,
                    'enum' => $param->enum,
                    'type' => $param->type,
                    'hidden' => $param->hidden,
                    'from_auth' => $param->fromAuth,
                ],
                $this->params,
            ),
        );
    }
}
