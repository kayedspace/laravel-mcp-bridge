<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Allowed includes: relationships, counts, exists checks and the aggregate
 * includes Spatie 7 added.
 *
 * Several names at once take an array, which keeps `type` and the
 * aggregate options as named arguments rather than entries in a variadic bag.
 *
 * On a model class the same declaration opens the relation to the model's
 * own `list` and `get` tools, read by `ModelSchema` and applied by
 * `ModelExecutor`. A relation in the model's `$hidden` stays out whatever
 * this says: hidden is the model's word, and it is final.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AllowInclude
{
    /**
     * @param  string|list<string>  $names
     * @param  string|null  $type  relation, count, exists, min, max, sum or avg; left unstated it is a plain relation
     * @param  string|null  $relation  the relation an aggregate include reads, when the name alone does not say
     * @param  string|null  $column  the column an aggregate include sums or averages
     * @param  string|null  $ability  a Gate ability the actor must hold for this include to exist for them; read by ApiQuery on every call
     */
    public function __construct(
        public string|array $names,
        public ?string $type = null,
        public ?string $relation = null,
        public ?string $column = null,
        public ?string $description = null,
        public ?string $ability = null,
    ) {}

    /** @return list<string> */
    public function names(): array
    {
        return is_string($this->names) ? [$this->names] : $this->names;
    }

    public function type(): string
    {
        return $this->type ?? 'relation';
    }

    /** This declaration for one of its names, so a list can be split and overridden one name at a time. */
    public function named(string $name): self
    {
        return new self(
            names: $name,
            type: $this->type,
            relation: $this->relation,
            column: $this->column,
            description: $this->description,
            ability: $this->ability,
        );
    }

    /** Layer an enriching declaration over this one. Stated values win. */
    public function mergedWith(self $other): self
    {
        return new self(
            names: $other->names,
            type: $other->type ?? $this->type,
            relation: $other->relation ?? $this->relation,
            column: $other->column ?? $this->column,
            description: $other->description ?? $this->description,
            ability: $other->ability ?? $this->ability,
        );
    }
}
