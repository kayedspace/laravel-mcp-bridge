<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Query;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;

/**
 * A query definition as tool inputs.
 *
 * Paths are the query parameter names themselves, read from Spatie's own
 * config, because the executor builds the query string from the path: a field
 * at `filter.status` leaves as `filter[status]=published`, which is exactly
 * what Spatie parses back. Lists carry their delimiter on the wire,
 * so `sort` arrives as `sort=-created_at,title` rather than as `sort[0]`.
 */
class QuerySchema
{
    /**
     * Marks a description this package generated from a template, as opposed
     * to one a developer wrote. A template is better than nothing and worse
     * than a sentence, so it yields to anything that said something.
     */
    public const GENERATED = 'query:generated-description';

    public function __construct(
        protected QueryParameters $parameters,
        protected Repository $config,
    ) {}

    /**
     * @param  int|null  $maxItems  the tool's own #[McpOutput(maxItems:)], which caps the page the agent may ask for the way it caps what it gets back
     */
    public function forDefinition(QueryDefinition $definition, ?int $maxItems = null): InputSchema
    {
        $hints = ModelHints::for($definition->model);

        $schema = InputSchema::empty();

        foreach ($definition->filters as $filter) {
            $schema = $schema->with(...$this->filter($filter, $hints));
        }

        $schema = $schema
            ->with(...$this->list('sort', $definition->sortValues(), $definition->defaultSort(), $this->sortDescription($definition)))
            ->with(...$this->list('include', $definition->includeNames(), null, $this->includeDescription($definition)))
            ->with(...$this->fields($definition, $hints))
            ->with(...$this->pagination($definition, $maxItems));

        return $schema;
    }

    /**
     * One filter. Spatie's own filter types decide the shape: `trashed` is an
     * enum of two values, `belongsTo` is a key, and the rest are typed from
     * the model's casts unless the declaration says otherwise.
     *
     * @return list<Field>
     */
    protected function filter(AllowFilter $filter, ModelHints $hints): array
    {
        $path = $this->parameter('filter').'.'.$filter->name;
        $attribute = $filter->column ?? $filter->name;

        $enum = $this->enum($filter, $hints, $attribute);

        $type = match (true) {
            $filter->type() === 'trashed' => FieldType::String,
            $filter->type() === 'belongsTo' => $hints->type($attribute) ?? FieldType::Integer,
            $enum !== [] => FieldType::String,
            default => $hints->type($attribute) ?? FieldType::String,
        };

        $description = $filter->description ?? $this->filterDescription($filter);

        // Marked when this package wrote the sentence, so a description that
        // came from a documentation attribute is not replaced by a template.
        $origin = $filter->description === null
            ? ['query:filter', self::GENERATED]
            : ['query:filter'];

        // A filter Spatie splits on the delimiter accepts several values, and
        // saying so in the schema is what stops an agent sending one string
        // with commas in it and hoping.
        if ($filter->multiple) {
            return [
                new Field(
                    path: $path,
                    type: FieldType::Array,
                    description: $description,
                    examples: $filter->example === null ? [] : [$filter->example],
                    wire: new Wire(in: WireIn::Query, name: $this->parameters->bracket($path), delimiter: $this->delimiter()),
                    origin: $origin,
                ),
                new Field(
                    path: $path.'.*',
                    type: $type,
                    enum: $enum,
                    origin: ['query:filter'],
                ),
            ];
        }

        return [new Field(
            path: $path,
            type: $type,
            description: $description,
            format: $filter->type() === 'trashed' ? null : $hints->format($attribute),
            enum: $filter->type() === 'trashed' ? ['with', 'only'] : $enum,
            examples: $filter->example === null ? [] : [$filter->example],
            wire: new Wire(in: WireIn::Query, name: $this->parameters->bracket($path)),
            origin: $origin,
        )];
    }

    /**
     * `sort`, `include` and every `fields[...]` key share one shape: a list of
     * allowed values that travels as a delimited string.
     *
     * @param  list<string>  $values
     * @return list<Field>
     */
    protected function list(string $parameter, array $values, ?string $default, ?string $description): array
    {
        if ($values === []) {
            return [];
        }

        $path = $this->parameter($parameter);

        return [
            new Field(
                path: $path,
                type: FieldType::Array,
                description: $description,
                max: $this->max($parameter),
                default: $default === null ? null : [$default],
                wire: new Wire(in: WireIn::Query, name: $path, delimiter: $this->delimiter()),
                origin: ["query:{$parameter}"],
            ),
            new Field(
                path: $path.'.*',
                type: FieldType::String,
                enum: $values,
                origin: ["query:{$parameter}"],
            ),
        ];
    }

    /**
     * Sparse fieldsets, one list per resource. The base resource is the
     * model's table, which is the key Spatie expects.
     *
     * @return list<Field>
     */
    protected function fields(QueryDefinition $definition, ModelHints $hints): array
    {
        $base = $definition->resource ?? $hints->table;
        $fields = [];

        foreach ($definition->fields as $resource => $columns) {
            $key = $resource === '*' ? $base : $resource;

            if ($key === null || $columns === []) {
                continue;
            }

            $path = $this->parameter('fields').'.'.$key;

            $fields[] = new Field(
                path: $path,
                type: FieldType::Array,
                description: "Return only these fields of {$key}.",
                wire: new Wire(in: WireIn::Query, name: $this->parameters->bracket($path), delimiter: $this->delimiter()),
                origin: ['query:fields'],
            );

            $fields[] = new Field(
                path: $path.'.*',
                type: FieldType::String,
                enum: $columns,
                origin: ['query:fields'],
            );
        }

        return $fields;
    }

    /**
     * Pagination inputs. `per_page` is capped by the output budget as well as
     * by the declaration, because the agent's context is the real limit.
     *
     * @return list<Field>
     */
    protected function pagination(QueryDefinition $definition, ?int $maxItems = null): array
    {
        $pagination = $definition->pagination;

        if ($pagination === null) {
            return [];
        }

        if ($pagination->isCursor()) {
            return [new Field(
                path: 'cursor',
                type: FieldType::String,
                description: 'The cursor from the previous page of results.',
                wire: new Wire(in: WireIn::Query, name: 'cursor'),
                origin: ['query:paginate'],
            )];
        }

        if ($pagination->fixed) {
            return [new Field(
                path: 'page',
                type: FieldType::Integer,
                description: "Which page of results to return, starting at 1. The page size is fixed at {$pagination->perPage}.",
                min: 1,
                wire: new Wire(in: WireIn::Query, name: 'page'),
                origin: ['query:paginate'],
            )];
        }

        $max = min(
            $pagination->max ?? $pagination->perPage,
            $maxItems ?? $this->config->integer('mcp-bridge.output.max_items', 25),
        );

        return [
            new Field(
                path: 'per_page',
                type: FieldType::Integer,
                description: "Results per page, at most {$max}.",
                min: 1,
                max: $max,
                default: min($pagination->perPage, $max),
                wire: new Wire(in: WireIn::Query, name: 'per_page'),
                origin: ['query:paginate'],
            ),
            new Field(
                path: 'page',
                type: FieldType::Integer,
                description: 'Which page of results to return, starting at 1.',
                min: 1,
                wire: new Wire(in: WireIn::Query, name: 'page'),
                origin: ['query:paginate'],
            ),
        ];
    }

    /**
     * The enum an agent may choose from: the declaration's own values, a
     * backed enum class it names, or the model's cast.
     *
     * @return list<string>
     */
    protected function enum(AllowFilter $filter, ModelHints $hints, string $attribute): array
    {
        if (is_string($filter->enum)) {
            return enum_exists($filter->enum)
                ? ModelHints::valuesOf($filter->enum)
                : [];
        }

        return $filter->enum === [] ? $hints->enumValues($attribute) : $filter->enum;
    }

    /** What the filter does, in the words an agent needs to use it correctly. */
    protected function filterDescription(AllowFilter $filter): string
    {
        $name = str_replace('_', ' ', $filter->name);

        return match ($filter->type()) {
            'exact' => "Keep only rows whose {$name} is exactly this.",
            'beginsWith' => "Keep only rows whose {$name} starts with this.",
            'endsWith' => "Keep only rows whose {$name} ends with this.",
            'scope' => "Apply the {$name} scope with this value.",
            'trashed' => 'Include deleted rows: with for all of them, only for the deleted ones.',
            'belongsTo' => "Keep only rows belonging to this {$name}.",
            'operator' => "Compare {$name}, as an operator and a value.",
            default => "Keep only rows whose {$name} contains this.",
        };
    }

    protected function sortDescription(QueryDefinition $definition): string
    {
        $default = $definition->defaultSort();

        // Stated in words as well as in the schema, because an agent reads the
        // description when it decides whether to pass the field at all.
        return 'Sort order. Prefix a field with - for descending.'
            .($default === null ? '' : " Defaults to {$default}.");
    }

    protected function includeDescription(QueryDefinition $definition): string
    {
        $aggregates = array_filter(
            $definition->includes,
            fn (AllowInclude $include): bool => $include->type() !== 'relation',
        );

        return $aggregates === []
            ? 'Related data to load alongside each row.'
            : 'Related data to load alongside each row. The Count, Exists and Sum names return a single value rather than the related rows.';
    }

    protected function parameter(string $name): string
    {
        return $this->parameters->name($name);
    }

    protected function delimiter(): string
    {
        return $this->parameters->delimiter();
    }

    protected function max(string $parameter): ?int
    {
        if ($parameter !== 'include') {
            return null;
        }

        $max = $this->config->integer('mcp-bridge.query.max_includes', 3);

        return $max > 0 ? $max : null;
    }
}
