<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * A compiled tool. The only thing the runtime reads, and the unit the
 * manifest stores, so every value here must survive a round trip through a
 * plain array.
 *
 * @phpstan-import-type TargetArray from Targets
 * @phpstan-import-type InputSchemaArray from InputSchema
 * @phpstan-import-type SafetyArray from Safety
 * @phpstan-import-type AuthorityArray from Authority
 * @phpstan-import-type LimitsArray from Limits
 * @phpstan-import-type OutputArray from Output
 * @phpstan-import-type DeprecationArray from Deprecation
 * @phpstan-import-type QueuedArray from Queued
 *
 * @phpstan-type ToolDefinitionArray array{name: string, candidate_id: string, source: string, description: string, target: TargetArray, input: InputSchemaArray, safety: SafetyArray, authority: AuthorityArray, limits: LimitsArray, server: string, module: string|null, title: string|null, groups: list<string>, origin: array<string, string>, output: OutputArray|null, deprecated: DeprecationArray|null, queue: QueuedArray|null}
 */
final readonly class ToolDefinition
{
    /**
     * @param  list<string>  $groups
     * @param  array<string, string>  $origin  which config layer set each option
     */
    public function __construct(
        public string $name,
        public string $candidateId,
        public string $source,
        public string $description,
        public Target $target,
        public InputSchema $input,
        public Safety $safety,
        public Authority $authority,
        public Limits $limits,
        public string $server = 'default',
        public ?string $module = null,
        public ?string $title = null,
        public array $groups = [],
        public array $origin = [],
        public ?Output $output = null,
        public ?Deprecation $deprecated = null,
        public ?Queued $queue = null,
    ) {}

    /** @return ToolDefinitionArray */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'candidate_id' => $this->candidateId,
            'source' => $this->source,
            'description' => $this->description,
            'target' => $this->target->toArray(),
            'input' => $this->input->toArray(),
            'safety' => $this->safety->toArray(),
            'authority' => $this->authority->toArray(),
            'limits' => $this->limits->toArray(),
            'server' => $this->server,
            'module' => $this->module,
            'title' => $this->title,
            'groups' => $this->groups,
            'origin' => $this->origin,
            'output' => $this->output?->toArray(),
            'deprecated' => $this->deprecated?->toArray(),
            'queue' => $this->queue?->toArray(),
        ];
    }

    /** @param ToolDefinitionArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            candidateId: $data['candidate_id'],
            source: $data['source'],
            description: $data['description'],
            target: Targets::fromArray($data['target']),
            input: InputSchema::fromArray($data['input']),
            safety: Safety::fromArray($data['safety']),
            authority: Authority::fromArray($data['authority']),
            limits: Limits::fromArray($data['limits']),
            server: $data['server'],
            module: $data['module'],
            title: $data['title'],
            groups: $data['groups'],
            origin: $data['origin'],
            output: $data['output'] === null ? null : Output::fromArray($data['output']),
            deprecated: $data['deprecated'] === null ? null : Deprecation::fromArray($data['deprecated']),
            queue: $data['queue'] === null ? null : Queued::fromArray($data['queue']),
        );
    }
}
