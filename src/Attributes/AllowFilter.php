<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * One allowed filter, mirroring Spatie's AllowedFilter vocabulary.
 *
 * The name deliberately differs from Spatie's own class names: Spatie ships no
 * attributes at all, so there is nothing to consume, and a future attribute of
 * theirs called AllowedFilter must not collide with this one.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AllowFilter
{
    /**
     * @param  string|null  $type  partial, exact, beginsWith, endsWith, scope, callback, custom, trashed, belongsTo
     *                             or operator; left unstated it is partial, which is what Spatie makes of a bare
     *                             string, and an attribute that only adds a description keeps the chain's type
     * @param  string|null  $column  the real column, when the public name is an alias; never exposed to the agent
     * @param  class-string|list<string>  $enum  a backed enum class, or the values themselves
     */
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?string $column = null,
        public array|string $enum = [],
        public ?string $description = null,
        public mixed $example = null,
        public bool $multiple = false,
    ) {}

    public function type(): string
    {
        return $this->type ?? 'partial';
    }

    /** Layer an enriching declaration over this one. Stated values win. */
    public function mergedWith(self $other): self
    {
        return new self(
            name: $other->name,
            type: $other->type ?? $this->type,
            column: $other->column ?? $this->column,
            enum: $other->enum === [] ? $this->enum : $other->enum,
            description: $other->description ?? $this->description,
            example: $other->example ?? $this->example,
            multiple: $other->multiple || $this->multiple,
        );
    }
}
