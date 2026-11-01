<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Query;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Contracts\ReadsQueryDefinitions;
use KayedSpace\McpBridge\Exceptions\InvalidQueryInput;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Builds the query a controller would have written by hand, from the same
 * declaration the agent schema is built from, so the REST behaviour and the
 * tool schema cannot drift apart:
 *
 *     return CourseResource::collection(ApiQuery::fromAttributes()->paginate());
 *
 * With Spatie installed this hands the declaration to Spatie and returns its
 * QueryBuilder, because Spatie already does this job properly. Without Spatie
 * it applies the same declaration to an Eloquent builder using the identical
 * query string format, so a team can adopt Spatie later without touching
 * either the attributes or the agent-facing schema.
 *
 * Teams with fully custom query logic simply do not call this: the attributes
 * then describe the inputs for the agent and change nothing else.
 */
class ApiQuery
{
    public function __construct(
        protected ReadsQueryDefinitions $reader,
        protected QueryParameters $parameters,
        protected Request $request,
        protected Gate $gate,
        protected Repository $config,
    ) {}

    /**
     * The same applier reading a request this one was handed, for a caller
     * that has arguments rather than an HTTP request: a model list tool
     * reaches the query layer through here.
     */
    public function withRequest(Request $request): static
    {
        $clone = clone $this;
        $clone->request = $request;

        return $clone;
    }

    /**
     * @param  class-string|null  $model  when the endpoint queries something other than #[QueryModel]
     * @return QueryBuilder<Model>|Builder<Model>
     */
    public static function fromAttributes(?string $model = null): object
    {
        return Container::getInstance()->make(self::class)->forCurrentRoute($model);
    }

    /**
     * @param  class-string|null  $model
     * @return QueryBuilder<Model>|Builder<Model>
     */
    public function forCurrentRoute(?string $model = null): object
    {
        $route = $this->request->route();

        $definition = $route instanceof Route
            ? $this->definitionFor($route)
            : null;

        if ($definition === null) {
            throw new RuntimeException(
                'No query definition was found for this route. Declare #[QueryModel] with the query '.
                'attributes on the action, or pass the model to ApiQuery::fromAttributes().',
            );
        }

        return $this->apply($definition, $model ?? $definition->model);
    }

    protected function definitionFor(Route $route): ?QueryDefinition
    {
        $action = $route->getActionName();

        if (! Str::contains($action, '@')) {
            return class_exists($action) ? $this->reader->read($action, '__invoke') : null;
        }

        [$class, $method] = explode('@', $action, 2);

        return class_exists($class) ? $this->reader->read($class, $method) : null;
    }

    /**
     * @param  class-string|null  $model
     * @return QueryBuilder<Model>|Builder<Model>
     */
    protected function apply(QueryDefinition $definition, ?string $model): object
    {
        if ($model === null || ! is_subclass_of($model, Model::class)) {
            throw new RuntimeException(
                'ApiQuery needs a model: declare #[QueryModel(Course::class)] or pass one in.',
            );
        }

        return class_exists(QueryBuilder::class)
            ? $this->spatieQuery($definition, $model)
            : $this->eloquentQuery($definition, $model);
    }

    /**
     * @param  class-string<Model>  $model
     * @return QueryBuilder<Model>
     */
    public function spatieQuery(QueryDefinition $definition, string $model): QueryBuilder
    {
        // The request is handed over rather than left to Spatie's container
        // binding, so this works the same whether or not Spatie's own
        // (deferred) provider has been resolved yet.
        $query = QueryBuilder::for($model, $this->request);

        // Each list only when it has entries: query builder 6 requires at
        // least one argument to each of these, and 7 accepts none.
        if ($definition->filters !== []) {
            $query->allowedFilters(...array_map(
                fn (AllowFilter $filter): AllowedFilter => $this->spatieFilter($filter),
                $definition->filters,
            ));
        }

        if ($definition->sorts !== []) {
            $query->allowedSorts(...array_map(
                fn ($sort): AllowedSort => AllowedSort::field($sort->name, $sort->column),
                $definition->sorts,
            ));
        }

        // Fields before includes: query builder 6 refuses the other order,
        // because an include's fields are checked against the allowed ones.
        $fields = $this->fieldList($definition);

        if ($fields !== []) {
            $query->allowedFields(...$fields);
        }

        $includes = array_values($this->allowedIncludes($definition));

        if ($includes !== []) {
            $query->allowedIncludes(...array_map($this->spatieInclude(...), $includes));
        }

        $default = $definition->defaultSort();

        if ($default !== null) {
            $query->defaultSort($default);
        }

        $this->capPerPage($definition, $query->getEloquentBuilder()->getModel());

        return $query;
    }

    protected function spatieFilter(AllowFilter $filter): AllowedFilter
    {
        return match ($filter->type()) {
            'exact' => AllowedFilter::exact($filter->name, $filter->column),
            'beginsWith' => AllowedFilter::beginsWith($filter->name, $filter->column),
            'endsWith' => AllowedFilter::endsWith($filter->name, $filter->column),
            'scope' => AllowedFilter::scope($filter->name, $filter->column),
            'trashed' => AllowedFilter::trashed($filter->name, $filter->column),
            'belongsTo' => AllowedFilter::belongsTo($filter->name, $filter->column),
            default => AllowedFilter::partial($filter->name, $filter->column),
        };
    }

    /**
     * Typed for query builder 7, which PHPStan analyses, where these helpers
     * return one include. Version 6 returns a collection of them instead, and
     * its `allowedIncludes()` takes that collection whole, so the value is
     * passed through untouched and the native return type stays open.
     *
     * @return AllowedInclude
     */
    protected function spatieInclude(AllowInclude $include): object
    {
        $name = $include->names()[0] ?? '';
        $relation = $include->relation ?? $name;
        $column = $include->column ?? '*';

        return match ($include->type()) {
            'count' => AllowedInclude::count($name),
            'exists' => AllowedInclude::exists($name),
            'min' => AllowedInclude::min($name, $relation, $column),
            'max' => AllowedInclude::max($name, $relation, $column),
            'sum' => AllowedInclude::sum($name, $relation, $column),
            'avg' => AllowedInclude::avg($name, $relation, $column),
            default => AllowedInclude::relationship($name),
        };
    }

    /**
     * The same declaration, applied without Spatie. Filter types that need a
     * callback or a Spatie filter class are skipped here rather than guessed
     * at: they only exist when Spatie does.
     *
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    public function eloquentQuery(QueryDefinition $definition, string $model): Builder
    {
        $query = $model::query();

        $this->applyFilters($query, $definition);
        $this->applySorts($query, $definition);
        $this->applyIncludes($query, $definition);
        $this->applyFields($query, $definition);
        $this->capPerPage($definition, $query->getModel());

        return $query;
    }

    /** @param Builder<Model> $query */
    protected function applyFilters(Builder $query, QueryDefinition $definition): void
    {
        $requested = $this->request->input($this->parameters->filter());

        if (! is_array($requested)) {
            return;
        }

        $allowed = [];

        foreach ($definition->filters as $filter) {
            $allowed[$filter->name] = $filter;
        }

        $unknown = array_values(array_diff(array_keys($requested), array_keys($allowed)));

        if ($unknown !== []) {
            throw InvalidQueryInput::for('filter', array_map('strval', $unknown), array_keys($allowed));
        }

        foreach ($requested as $name => $value) {
            $filter = $allowed[$name] ?? null;

            if ($filter === null || $value === null || $value === '') {
                continue;
            }

            $this->applyFilter($query, $filter, $value);
        }
    }

    /** @param Builder<Model> $query */
    protected function applyFilter(Builder $query, AllowFilter $filter, mixed $value): void
    {
        $column = $filter->column ?? $filter->name;
        $values = $this->parameters->values($value);
        $first = $values[0] ?? '';

        match ($filter->type()) {
            'exact' => count($values) > 1
                ? $query->whereIn($column, $values)
                : $query->where($column, $first),
            'beginsWith' => $query->where($column, 'like', $first.'%'),
            'endsWith' => $query->where($column, 'like', '%'.$first),
            'scope' => $query->{$column}(count($values) > 1 ? $values : $first),
            'trashed' => $this->applyTrashed($query, $first),
            'belongsTo' => $query->where($column, $first),
            // callback, custom and operator filters are Spatie's own; without
            // it there is nothing to call.
            'callback', 'custom', 'operator' => null,
            default => $query->where($column, 'like', '%'.$first.'%'),
        };
    }

    /** @param Builder<Model> $query */
    protected function applyTrashed(Builder $query, string $value): void
    {
        if ($value !== 'with' && $value !== 'only') {
            return;
        }

        // withTrashed() and onlyTrashed() come from the SoftDeletes trait, so
        // they exist only on a model that uses it. Lifting the global scope is
        // what those methods do, and it is safe on a model without the trait,
        // where there is no such scope to lift.
        $query->withoutGlobalScope(SoftDeletingScope::class);

        if ($value === 'only') {
            $query->whereNotNull($this->deletedAtColumn($query->getModel()));
        }
    }

    /** Mirrors SoftDeletes::getDeletedAtColumn() without needing the trait. */
    protected function deletedAtColumn(Model $model): string
    {
        $constant = $model::class.'::DELETED_AT';

        if (! defined($constant)) {
            return 'deleted_at';
        }

        $column = constant($constant);

        return is_string($column) ? $column : 'deleted_at';
    }

    /** @param Builder<Model> $query */
    protected function applySorts(Builder $query, QueryDefinition $definition): void
    {
        $requested = $this->parameters->values($this->request->input($this->parameters->sort()));

        if ($requested === []) {
            $default = $definition->defaultSort();

            $requested = $default === null ? [] : [$default];
        }

        $allowed = [];

        foreach ($definition->sorts as $sort) {
            $allowed[$sort->name] = $sort->column ?? $sort->name;
        }

        foreach ($requested as $value) {
            $name = ltrim($value, '-');
            $column = $allowed[$name] ?? null;

            if ($column === null) {
                throw InvalidQueryInput::for('sort', [$name], array_keys($allowed));
            }

            $query->orderBy($column, str_starts_with($value, '-') ? 'desc' : 'asc');
        }
    }

    /** @param Builder<Model> $query */
    protected function applyIncludes(Builder $query, QueryDefinition $definition): void
    {
        foreach ($this->requestedIncludes($definition) as $name => $include) {
            [$method, $arguments] = $this->includeCall('with', $name, $include);

            $query->{$method}(...$arguments);
        }
    }

    /**
     * The same includes onto a record already fetched, for a `get` whose
     * policy is asked about the record before anything related is read.
     */
    public function loadIncludes(Model $record, QueryDefinition $definition): void
    {
        foreach ($this->requestedIncludes($definition) as $name => $include) {
            [$method, $arguments] = $this->includeCall('load', $name, $include);

            $record->{$method}(...$arguments);
        }
    }

    /**
     * The relations the request asked for, by root name, so a caller
     * serialising a record keeps exactly those and nothing the model loads
     * on its own through `$with`, which no ability was asked about.
     *
     * @return list<string>
     */
    public function requestedRelations(QueryDefinition $definition): array
    {
        $relations = [];

        foreach ($this->requestedIncludes($definition) as $name => $include) {
            if ($include->type() === 'relation') {
                $relations[] = Str::before($name, '.');
            }
        }

        return array_values(array_unique($relations));
    }

    /**
     * One include as the Eloquent call that loads it. A relation is
     * `with`/`load`; a count, exists or aggregate is the same verb with the
     * type appended, which is why the two paths differ only in the verb.
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function includeCall(string $verb, string $name, AllowInclude $include): array
    {
        $type = $include->type();

        if ($type === 'relation') {
            return [$verb, [$name]];
        }

        $suffix = ucfirst($type);
        $relation = $include->relation ?? (Str::endsWith($name, $suffix) ? Str::beforeLast($name, $suffix) : $name);

        return in_array($type, ['count', 'exists'], true)
            ? [$verb.$suffix, [$relation]]
            : [$verb.$suffix, [$relation, $include->column ?? '*']];
    }

    /**
     * The includes the request asked for, each checked against what this
     * actor is allowed. One an actor may not have reads as unknown, and the
     * message lists what they may: a relation the Gate closed is, for them,
     * a relation that does not exist.
     *
     * @return array<string, AllowInclude>
     */
    protected function requestedIncludes(QueryDefinition $definition): array
    {
        $requested = $this->parameters->values($this->request->input($this->parameters->include()));

        if ($requested === []) {
            return [];
        }

        $allowed = $this->allowedIncludes($definition);
        $unknown = array_values(array_diff($requested, array_keys($allowed)));

        if ($unknown !== []) {
            throw InvalidQueryInput::for('include', $unknown, array_keys($allowed));
        }

        // The schema says how many, and the schema is advice until something
        // here holds the call to it.
        $max = $this->config->integer('mcp-bridge.query.max_includes', 3);

        if ($max > 0 && count($requested) > $max) {
            throw new InvalidQueryInput(400, sprintf('At most %d include(s) per call; %d were requested.', $max, count($requested)));
        }

        $includes = [];

        foreach ($requested as $name) {
            $includes[$name] = $allowed[$name];
        }

        return $includes;
    }

    /**
     * The declared includes less those whose ability the Gate denies the
     * current actor, keyed by name.
     *
     * @return array<string, AllowInclude>
     */
    protected function allowedIncludes(QueryDefinition $definition): array
    {
        $allowed = [];

        foreach ($definition->includes as $include) {
            if ($include->ability !== null && ! $this->gate->allows($include->ability)) {
                continue;
            }

            foreach ($include->names() as $name) {
                $allowed[$name] = $include;
            }
        }

        return $allowed;
    }

    /**
     * Sparse fieldsets for the queried resource. Related fieldsets are
     * Spatie's own trick, and are left to it.
     *
     * @param  Builder<Model>  $query
     */
    protected function applyFields(Builder $query, QueryDefinition $definition): void
    {
        $requested = $this->request->input($this->parameters->fields());

        if (! is_array($requested) || $requested === []) {
            return;
        }

        $resource = $definition->resource ?? $query->getModel()->getTable();
        $allowed = $definition->fields['*'] ?? $definition->fields[$resource] ?? [];

        if ($allowed === []) {
            return;
        }

        $fields = $this->parameters->values($requested[$resource] ?? null);

        if ($fields === []) {
            return;
        }

        $unknown = array_values(array_diff($fields, $allowed));

        if ($unknown !== []) {
            throw InvalidQueryInput::for('field', $unknown, $allowed);
        }

        $query->select($fields);
    }

    /**
     * `per_page` from the request, clamped to what the endpoint declared, set
     * on the model so a plain ->paginate() picks it up. The cap matters: an
     * agent asking for 10,000 rows would otherwise get them.
     */
    protected function capPerPage(QueryDefinition $definition, Model $model): void
    {
        $pagination = $definition->pagination;

        if ($pagination === null) {
            return;
        }

        $max = $pagination->max ?? $pagination->perPage;
        $requested = $this->request->integer('per_page');

        $model->setPerPage($requested < 1 ? $pagination->perPage : min($requested, $max));
    }

    /**
     * Spatie's allowedFields() wants `id` for the base resource and
     * `lessons.title` for a related one, which is how the definition stores
     * them.
     *
     * @return list<string>
     */
    protected function fieldList(QueryDefinition $definition): array
    {
        $fields = [];

        foreach ($definition->fields as $resource => $columns) {
            foreach ($columns as $column) {
                $fields[] = $resource === '*' ? $column : "{$resource}.{$column}";
            }
        }

        return $fields;
    }
}
