<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

use KayedSpace\McpBridge\Enums\FieldType;

/**
 * One input field in the intermediate representation, before it is compiled
 * into an Illuminate\JsonSchema type.
 *
 * @phpstan-import-type WireArray from Wire
 *
 * @phpstan-type FieldArray array{path: string, type: string, required: bool, nullable: bool, description: string|null, format: string|null, pattern: string|null, enum: list<string>, min: int|float|null, max: int|float|null, default: mixed, examples: list<mixed>, hidden: bool, filled_from: string|null, wire: WireArray|null, origin: list<string>, unexpressible: list<string>}
 */
final readonly class Field
{
    /**
     * @param  list<string>  $enum
     * @param  list<mixed>  $examples
     * @param  list<string>  $origin  every layer that set a value here, in order
     * @param  list<string>  $unexpressible  constraints kept as prose only
     */
    public function __construct(
        public string $path,
        public FieldType $type,
        public bool $required = false,
        public bool $nullable = false,
        public ?string $description = null,
        public ?string $format = null,
        public ?string $pattern = null,
        public array $enum = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
        public mixed $default = null,
        public array $examples = [],
        public bool $hidden = false,
        public ?string $filledFrom = null,
        public ?Wire $wire = null,
        public array $origin = [],
        public array $unexpressible = [],
    ) {}

    /**
     * A copy with some values changed, which is the only way a readonly field
     * is ever modified. Every layer of the schema pipeline needs this, and
     * each one writing out all seventeen arguments is how a new one gets
     * forgotten.
     *
     * A null means "leave it alone", so a value cannot be cleared through
     * here. Nothing needs to: a later layer describes a field more fully than
     * an earlier one, never less.
     *
     * @param  list<string>|null  $enum
     * @param  list<mixed>|null  $examples
     * @param  list<string>|null  $origin
     * @param  list<string>|null  $unexpressible
     */
    public function with(
        ?FieldType $type = null,
        ?bool $required = null,
        ?bool $nullable = null,
        ?string $description = null,
        ?string $format = null,
        ?string $pattern = null,
        ?array $enum = null,
        int|float|null $min = null,
        int|float|null $max = null,
        mixed $default = null,
        ?array $examples = null,
        ?bool $hidden = null,
        ?string $filledFrom = null,
        ?Wire $wire = null,
        ?array $origin = null,
        ?array $unexpressible = null,
    ): self {
        return new self(
            path: $this->path,
            type: $type ?? $this->type,
            required: $required ?? $this->required,
            nullable: $nullable ?? $this->nullable,
            description: $description ?? $this->description,
            format: $format ?? $this->format,
            pattern: $pattern ?? $this->pattern,
            enum: $enum ?? $this->enum,
            min: $min ?? $this->min,
            max: $max ?? $this->max,
            default: $default ?? $this->default,
            examples: $examples ?? $this->examples,
            hidden: $hidden ?? $this->hidden,
            filledFrom: $filledFrom ?? $this->filledFrom,
            wire: $wire ?? $this->wire,
            origin: $origin ?? $this->origin,
            unexpressible: $unexpressible ?? $this->unexpressible,
        );
    }

    /**
     * Layer a later declaration over this field. Set values win, and the
     * origin trail is appended rather than replaced.
     */
    public function mergedWith(self $other): self
    {
        return $this->with(
            type: $other->type,
            required: $other->required || $this->required,
            nullable: $other->nullable || $this->nullable,
            description: $other->description,
            format: $other->format,
            pattern: $other->pattern,
            enum: $other->enum ?: null,
            min: $other->min,
            max: $other->max,
            default: $other->default,
            examples: $other->examples ?: null,
            hidden: $other->hidden || $this->hidden,
            filledFrom: $other->filledFrom,
            wire: $other->wire,
            origin: [...$this->origin, ...$other->origin],
            unexpressible: [...$this->unexpressible, ...$other->unexpressible],
        );
    }

    /**
     * Present in the schema so the executor knows the field's wire, never
     * shown to the agent and never taken from it.
     */
    public function hidden(string $reason, ?string $filledFrom = null): self
    {
        return $this->with(
            required: false,
            hidden: true,
            filledFrom: $filledFrom,
            origin: [...$this->origin, $reason],
        );
    }

    /** @return FieldArray */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type->value,
            'required' => $this->required,
            'nullable' => $this->nullable,
            'description' => $this->description,
            'format' => $this->format,
            'pattern' => $this->pattern,
            'enum' => $this->enum,
            'min' => $this->min,
            'max' => $this->max,
            'default' => $this->default,
            'examples' => $this->examples,
            'hidden' => $this->hidden,
            'filled_from' => $this->filledFrom,
            'wire' => $this->wire?->toArray(),
            'origin' => $this->origin,
            'unexpressible' => $this->unexpressible,
        ];
    }

    /** @param FieldArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['path'],
            type: FieldType::from($data['type']),
            required: $data['required'],
            nullable: $data['nullable'],
            description: $data['description'],
            format: $data['format'],
            pattern: $data['pattern'],
            enum: $data['enum'],
            min: $data['min'],
            max: $data['max'],
            default: $data['default'],
            examples: $data['examples'],
            hidden: $data['hidden'],
            filledFrom: $data['filled_from'],
            wire: $data['wire'] === null ? null : Wire::fromArray($data['wire']),
            origin: $data['origin'],
            unexpressible: $data['unexpressible'],
        );
    }
}
