<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\Paginate;
use KayedSpace\McpBridge\Audit\Redactor;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Query\ModelHints;
use KayedSpace\McpBridge\Query\QueryAttributeReader;
use KayedSpace\McpBridge\Query\QueryDefinition;
use KayedSpace\McpBridge\Query\QuerySchema;

/**
 * What an agent may send for each model operation.
 *
 * A model already says most of it: `$fillable` is the set of attributes the
 * application lets anything mass-assign, and the casts give their types. Only
 * those are exposed, so a model tool cannot write a column the application
 * itself protects.
 *
 * A list operation goes through the same query layer a route list does, so a
 * model list and a Spatie-backed list look the same to an agent and serialise
 * the same way.
 */
class ModelSchema
{
    public function __construct(
        protected QuerySchema $queries,
        protected QueryAttributeReader $attributes,
        protected FormRequestReader $requests,
        protected RuleMapper $rules,
        protected Redactor $redactor,
        protected Repository $config,
    ) {}

    /**
     * @param  class-string<Model>  $model
     * @param  class-string|null  $rules  a request whose rules describe a write, from #[McpInput]
     */
    public function forOperation(string $model, string $operation, ?string $rules = null, ?int $maxItems = null): InputSchema
    {
        return match ($operation) {
            'list' => $this->list($model, $maxItems),
            'get' => $this->key($model)->merge($this->includes($model)),
            'delete' => $this->key($model),
            'create' => $this->attributes($model, $rules),
            'update' => $this->key($model)->merge($this->attributes($model, $rules)),
            default => InputSchema::empty(),
        };
    }

    /** @param class-string<Model> $model */
    protected function list(string $model, ?int $maxItems = null): InputSchema
    {
        return $this->queries->forDefinition($this->definitionFor($model, $maxItems), $maxItems);
    }

    /**
     * What a list of this model allows, as the same definition a fluent Spatie
     * chain would have produced. The executor builds its query from this, so
     * what the agent was offered is exactly what the applier accepts.
     *
     * @param  class-string<Model>  $model
     */
    public function definitionFor(string $model, ?int $maxItems = null): QueryDefinition
    {
        $hints = ModelHints::for($model);
        $instance = new $model;

        $filters = [];

        foreach ($this->filterable($instance) as $attribute) {
            // A typed column is matched exactly, because a partial match on a
            // number or a date is nonsense. An untyped one is text as far as
            // anything here knows, and a contains match is what an agent
            // searching text wants.
            $typed = $hints->type($attribute) ?? FieldType::String;

            $filters[] = new AllowFilter(
                name: $attribute,
                type: $typed === FieldType::String && $hints->enumValues($attribute) === []
                    ? 'partial'
                    : 'exact',
            );
        }

        $sorts = [new AllowSort($instance->getKeyName())];

        foreach ($this->sortable($instance) as $attribute) {
            $sorts[] = new AllowSort($attribute);
        }

        $max = $maxItems ?? $this->config->integer('mcp-bridge.output.max_items', 25);

        $inferred = new QueryDefinition(
            model: $model,
            filters: $filters,
            sorts: $sorts,
            pagination: new Paginate(perPage: min(15, $max), max: $max),
            origin: 'model',
        );

        // The model's own query attributes layer over what was inferred, the
        // way they layer under a controller's for a route. A declared filter
        // refines an inferred one, and #[AllowInclude] is the only way a
        // relation is reached: nothing infers which relations are for agents.
        $declared = $this->attributes->read($model);
        $definition = $declared === null ? $inferred : $inferred->mergedWith($declared);

        return $this->withoutHiddenIncludes($definition, $instance)
            ->withIncludeDepth($this->config->integer('mcp-bridge.query.max_include_depth', 2));
    }

    /**
     * A relation in `$hidden` is never loaded for an agent, whatever an
     * attribute declared. Hidden is the model's word, and `toArray()` would
     * drop it on the way out anyway; leaving it offered would be an input
     * that costs a query and changes nothing.
     */
    protected function withoutHiddenIncludes(QueryDefinition $definition, Model $instance): QueryDefinition
    {
        $hidden = $instance->getHidden();

        $includes = array_values(array_filter(
            $definition->includes,
            function (AllowInclude $include) use ($hidden): bool {
                $name = $include->names()[0] ?? '';
                $relation = $include->relation ?? (Str::endsWith($name, 'Count') ? Str::beforeLast($name, 'Count') : $name);
                $root = Str::before($relation, '.');

                return ! in_array($root, $hidden, true);
            },
        ));

        return new QueryDefinition(
            model: $definition->model,
            resource: $definition->resource,
            filters: $definition->filters,
            sorts: $definition->sorts,
            includes: $includes,
            fields: $definition->fields,
            pagination: $definition->pagination,
            origin: $definition->origin,
        );
    }

    /**
     * The include input alone, for `get`: what a list may load, loaded onto
     * one record.
     *
     * @param  class-string<Model>  $model
     */
    protected function includes(string $model): InputSchema
    {
        $includes = $this->definitionFor($model)->includes;

        return $includes === []
            ? InputSchema::empty()
            : $this->queries->forDefinition(new QueryDefinition(model: $model, includes: $includes));
    }

    /** @param class-string<Model> $model */
    protected function key(string $model): InputSchema
    {
        $instance = new $model;
        $name = $instance->getKeyName();

        return InputSchema::empty()->with(new Field(
            path: $name,
            type: $instance->getKeyType() === 'int' ? FieldType::Integer : FieldType::String,
            required: true,
            description: 'Which '.class_basename($model).' to act on.',
            wire: new Wire(in: WireIn::Body, name: $name),
            origin: ['model:key'],
        ));
    }

    /**
     * The writable attributes. A FormRequest named with #[McpInput] wins,
     * because then the application has already written down what a valid
     * write looks like, including what is required.
     *
     * @param  class-string<Model>  $model
     * @param  class-string|null  $rules
     */
    protected function attributes(string $model, ?string $rules): InputSchema
    {
        if ($rules !== null) {
            return $this->fromRules($rules);
        }

        $hints = ModelHints::for($model);
        $instance = new $model;
        $schema = InputSchema::empty();

        foreach ($this->exposed($instance) as $attribute) {
            $schema = $schema->with(new Field(
                path: $attribute,
                type: $hints->type($attribute) ?? FieldType::String,
                format: $hints->format($attribute),
                enum: $hints->enumValues($attribute),
                wire: new Wire(in: WireIn::Body, name: $attribute),
                origin: ['model:fillable'],
            ));
        }

        return $schema->sorted();
    }

    /**
     * A write described by the application's own validation rules, which say
     * what is required as well as what is allowed.
     *
     * @param  class-string  $rules
     */
    protected function fromRules(string $rules): InputSchema
    {
        $schema = InputSchema::empty();

        foreach ($this->requests->rulesFor($rules) as $path => $rule) {
            $schema = $schema->with($this->rules->map((string) $path, $rule, WireIn::Body));
        }

        return $schema->sorted();
    }

    /**
     * Mass-assignable attributes are the honest set to filter, sort and
     * write on: a model that guards an attribute from mass assignment is
     * saying it is not for arbitrary callers, and an agent is the most
     * arbitrary caller there is.
     *
     * Less the ones the model hides and the ones the redaction list names.
     * The output never shows `password`, and a filter on it would still be
     * a prefix oracle on the hash, a sort on it would still order by it,
     * and a write to it would still set it. Laravel's default User has it
     * fillable and hidden, which is exactly this case. A write to such a
     * column is declared on purpose, through `#[McpInput]`.
     *
     * @return list<string>
     */
    protected function exposed(Model $model): array
    {
        $hidden = $model->getHidden();

        return array_values(array_filter(
            $model->getFillable(),
            fn (string $attribute): bool => ! str_contains($attribute, '.')
                && ! in_array($attribute, $hidden, true)
                && ! $this->redactor->isSecret($attribute),
        ));
    }

    /** @return list<string> */
    protected function filterable(Model $model): array
    {
        return $this->exposed($model);
    }

    /** @return list<string> */
    protected function sortable(Model $model): array
    {
        $sortable = $this->filterable($model);

        if ($model->usesTimestamps()) {
            $sortable[] = $model->getCreatedAtColumn() ?? 'created_at';
            $sortable[] = $model->getUpdatedAtColumn() ?? 'updated_at';
        }

        return array_values(array_unique($sortable));
    }
}
