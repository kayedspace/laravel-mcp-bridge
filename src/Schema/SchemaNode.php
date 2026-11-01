<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use KayedSpace\McpBridge\Data\Field;

/**
 * One node while a dotted field list is being folded back into a tree, so
 * that `filter.title` becomes a `filter` object and `tags.*` becomes an array
 * of its member type.
 */
final class SchemaNode
{
    public ?Field $field = null;

    /** @var array<string, self> */
    public array $children = [];

    public function child(string $segment): self
    {
        return $this->children[$segment] ??= new self;
    }

    public function isLeaf(): bool
    {
        return $this->children === [];
    }

    /** Laravel's wildcard: the node describes array members, not properties. */
    public function isCollection(): bool
    {
        return array_keys($this->children) === ['*'];
    }
}
