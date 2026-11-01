<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Sparse fieldsets: which columns an agent may ask for, per resource. The
 * resource defaults to the queried model's table, which is the key Spatie
 * expects in `fields[...]`.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AllowFields
{
    /**
     * @param  string|list<string>  $fields
     * @param  string|null  $resource  the `fields[...]` key, when it is not the base resource
     */
    public function __construct(
        public string|array $fields,
        public ?string $resource = null,
    ) {}

    /** @return list<string> */
    public function fields(): array
    {
        return is_string($this->fields) ? [$this->fields] : $this->fields;
    }
}
