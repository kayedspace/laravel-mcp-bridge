<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Query;

use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\Paginate;

/**
 * What a list endpoint allows an agent to ask for.
 *
 * The query attributes are the vocabulary, so they are also the value objects
 * here: a definition read from a fluent Spatie chain carries the same
 * AllowFilter and AllowSort instances a declared one does, which is what lets
 * mcp-bridge:query-attributes print a chain back as attributes.
 */
final readonly class QueryDefinition
{
    /**
     * @param  class-string|null  $model
     * @param  list<AllowFilter>  $filters
     * @param  list<AllowSort>  $sorts
     * @param  list<AllowInclude>  $includes
     * @param  array<string, list<string>>  $fields  allowed columns, keyed by `fields[...]` resource
     * @param  string  $origin  attributes, definition or chain, for --explain and the divergence lint
     */
    public function __construct(
        public ?string $model = null,
        public ?string $resource = null,
        public array $filters = [],
        public array $sorts = [],
        public array $includes = [],
        public array $fields = [],
        public ?Paginate $pagination = null,
        public string $origin = 'attributes',
    ) {}

    public function isEmpty(): bool
    {
        return $this->filters === []
            && $this->sorts === []
            && $this->includes === []
            && $this->fields === []
            && $this->pagination === null;
    }

    /** The sort applied when the agent names none, in Spatie's own `-field` notation. */
    public function defaultSort(): ?string
    {
        foreach ($this->sorts as $sort) {
            $value = $sort->defaultValue();

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Every sort value an agent may pass: ascending and descending for each
     * allowed field.
     *
     * @return list<string>
     */
    public function sortValues(): array
    {
        $values = [];

        foreach ($this->sorts as $sort) {
            $values[] = $sort->name;
            $values[] = '-'.$sort->name;
        }

        return $values;
    }

    /**
     * Include names, capped by depth so an agent cannot walk a relation tree
     * the application never meant to expose.
     *
     * @return list<string>
     */
    public function includeNames(?int $maxDepth = null): array
    {
        $names = [];

        foreach ($this->includes as $include) {
            foreach ($include->names() as $name) {
                if ($maxDepth !== null && substr_count($name, '.') + 1 > $maxDepth) {
                    continue;
                }

                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Drop the includes that reach deeper than the application allows, and
     * leave one declaration per include name so later layers can override
     * them one at a time.
     */
    public function withIncludeDepth(int $maxDepth): self
    {
        $includes = [];

        foreach ($this->includes as $include) {
            foreach ($include->names() as $name) {
                if (substr_count($name, '.') + 1 > $maxDepth) {
                    continue;
                }

                $includes[$name] = $include->named($name);
            }
        }

        return new self(
            model: $this->model,
            resource: $this->resource,
            filters: $this->filters,
            sorts: $this->sorts,
            includes: array_values($includes),
            fields: $this->fields,
            pagination: $this->pagination,
            origin: $this->origin,
        );
    }

    /**
     * Layer a more specific definition over this one, per name.
     *
     * This is what lets a team keep its fluent chain and add one attribute to
     * give a filter a description or an enum: the chain decides what exists,
     * the attribute enriches it, and neither has to repeat the other.
     */
    public function mergedWith(self $other): self
    {
        return new self(
            model: $other->model ?? $this->model,
            resource: $other->resource ?? $this->resource,
            filters: $this->mergeFilters($other->filters),
            sorts: $this->mergeSorts($other->sorts),
            includes: $this->mergeIncludes($other->includes),
            fields: [...$this->fields, ...$other->fields],
            pagination: $other->pagination ?? $this->pagination,
            origin: $this->origin === $other->origin ? $this->origin : "{$this->origin}+{$other->origin}",
        );
    }

    /**
     * @param  list<AllowFilter>  $others
     * @return list<AllowFilter>
     */
    protected function mergeFilters(array $others): array
    {
        $filters = [];

        foreach ($this->filters as $filter) {
            $filters[$filter->name] = $filter;
        }

        foreach ($others as $filter) {
            $filters[$filter->name] = isset($filters[$filter->name])
                ? $filters[$filter->name]->mergedWith($filter)
                : $filter;
        }

        return array_values($filters);
    }

    /**
     * @param  list<AllowSort>  $others
     * @return list<AllowSort>
     */
    protected function mergeSorts(array $others): array
    {
        $sorts = [];

        foreach ($this->sorts as $sort) {
            $sorts[$sort->name] = $sort;
        }

        foreach ($others as $sort) {
            $sorts[$sort->name] = isset($sorts[$sort->name])
                ? $sorts[$sort->name]->mergedWith($sort)
                : $sort;
        }

        return array_values($sorts);
    }

    /**
     * @param  list<AllowInclude>  $others
     * @return list<AllowInclude>
     */
    protected function mergeIncludes(array $others): array
    {
        $includes = [];

        foreach ([...$this->includes, ...$others] as $include) {
            foreach ($include->names() as $name) {
                $single = $include->named($name);

                $includes[$name] = isset($includes[$name])
                    ? $includes[$name]->mergedWith($single)
                    : $single;
            }
        }

        return array_values($includes);
    }
}
