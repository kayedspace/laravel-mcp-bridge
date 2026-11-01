<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * Something a source could expose. Not yet named, not yet authorized, not yet
 * a tool.
 *
 * @phpstan-import-type TargetArray from Targets
 * @phpstan-import-type SafetyArray from Safety
 * @phpstan-import-type DeclaredArray from Declared
 *
 * @phpstan-type ToolCandidateArray array{source: string, id: string, target: TargetArray, module: string|null, suggested_name: string|null, title: string|null, description: string|null, groups: list<string>, safety: SafetyArray|null, declarations: array<string, array<array-key, mixed>>, meta: array<string, mixed>, declared: DeclaredArray|null}
 */
final readonly class ToolCandidate
{
    /**
     * @param  string  $id  stable and independent of discovery order, because config
     *                      overrides, audit history and snapshots are keyed by it
     * @param  list<string>  $groups
     * @param  array<string, array<array-key, mixed>>  $declarations  attributes found, keyed by class name, with their raw arguments
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $source,
        public string $id,
        public Target $target,
        public ?string $module = null,
        public ?string $suggestedName = null,
        public ?string $title = null,
        public ?string $description = null,
        public array $groups = [],
        public ?Safety $safety = null,
        public array $declarations = [],
        public array $meta = [],
        public ?Declared $declared = null,
    ) {}

    /** @return ToolCandidateArray */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'id' => $this->id,
            'target' => $this->target->toArray(),
            'module' => $this->module,
            'suggested_name' => $this->suggestedName,
            'title' => $this->title,
            'description' => $this->description,
            'groups' => $this->groups,
            'safety' => $this->safety?->toArray(),
            'declarations' => $this->declarations,
            'meta' => $this->meta,
            'declared' => $this->declared?->toArray(),
        ];
    }

    /** @param ToolCandidateArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            id: $data['id'],
            target: Targets::fromArray($data['target']),
            module: $data['module'],
            suggestedName: $data['suggested_name'],
            title: $data['title'],
            description: $data['description'],
            groups: $data['groups'],
            safety: $data['safety'] === null ? null : Safety::fromArray($data['safety']),
            declarations: $data['declarations'],
            meta: $data['meta'],
            declared: $data['declared'] === null ? null : Declared::fromArray($data['declared']),
        );
    }
}
