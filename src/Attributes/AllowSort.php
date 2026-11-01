<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * One allowed sort. `default` makes this the sort applied when the agent names
 * none, which is Spatie's defaultSort().
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AllowSort
{
    /**
     * @param  string|null  $default  asc or desc, when this field is the default sort
     * @param  class-string|null  $using  a Spatie Sort class for a custom sort
     */
    public function __construct(
        public string $name,
        public ?string $default = null,
        public ?string $column = null,
        public ?string $using = null,
        public ?string $description = null,
    ) {}

    /** The value the agent passes, and the one Spatie expects: -field for descending. */
    public function defaultValue(): ?string
    {
        return match ($this->default) {
            'desc' => '-'.$this->name,
            'asc' => $this->name,
            default => null,
        };
    }

    /** Layer an enriching declaration over this one. Stated values win. */
    public function mergedWith(self $other): self
    {
        return new self(
            name: $other->name,
            default: $other->default ?? $this->default,
            column: $other->column ?? $this->column,
            using: $other->using ?? $this->using,
            description: $other->description ?? $this->description,
        );
    }
}
