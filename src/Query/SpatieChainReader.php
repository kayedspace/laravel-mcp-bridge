<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Query;

use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\Paginate;
use KayedSpace\McpBridge\Contracts\ReadsQueryDefinitions;
use KayedSpace\McpBridge\Support\Ast;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;

/**
 * Reads an existing fluent Spatie chain, so a team that already wrote
 * `QueryBuilder::for(Course::class)->allowedFilters(...)` gets typed tool
 * inputs without adding a single attribute.
 *
 * Static analysis, never execution: the method is parsed, not called, because
 * calling a controller action to find out what it allows would hit the
 * database and the request it expects.
 *
 * ponytail: literal arguments only. A name built from a constant, a variable
 * or a match() is skipped rather than guessed; declare those with the query
 * attributes, which are read first anyway.
 */
class SpatieChainReader implements ReadsQueryDefinitions
{
    /** Spatie's AllowedFilter factories, mapped onto this package's filter types. */
    protected const FILTERS = [
        'exact' => 'exact',
        'partial' => 'partial',
        'beginsWith' => 'beginsWith',
        'beginsWithStrict' => 'beginsWith',
        'endsWith' => 'endsWith',
        'endsWithStrict' => 'endsWith',
        'belongsTo' => 'belongsTo',
        'scope' => 'scope',
        'callback' => 'callback',
        'trashed' => 'trashed',
        'custom' => 'custom',
        'operator' => 'operator',
        'groupOr' => 'custom',
        'groupAnd' => 'custom',
    ];

    /** Spatie's AllowedInclude factories. `relationship` is a plain relation. */
    protected const INCLUDES = [
        'relationship' => 'relation',
        'count' => 'count',
        'exists' => 'exists',
        'min' => 'min',
        'max' => 'max',
        'sum' => 'sum',
        'avg' => 'avg',
    ];

    protected const PAGINATION = [
        'paginate' => 'length-aware',
        'simplePaginate' => 'simple',
        'cursorPaginate' => 'cursor',
    ];

    public function __construct(protected Ast $ast) {}

    /** @param class-string $class */
    public function read(string $class, ?string $method = null): ?QueryDefinition
    {
        if ($method === null || ! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod($method)) {
            return null;
        }

        $node = $this->ast->methodNode($reflection->getMethod($method));

        return $node === null ? null : $this->definition($node);
    }

    protected function definition(Node\Stmt\ClassMethod $method): ?QueryDefinition
    {
        $finder = new NodeFinder;

        $filters = [];
        $sorts = [];
        $includes = [];
        $fields = [];
        $model = null;
        $pagination = null;
        $defaultSort = null;

        /** @var list<Node\Expr\StaticCall> $statics */
        $statics = $finder->findInstanceOf($method, Node\Expr\StaticCall::class);

        foreach ($statics as $call) {
            if ($this->shortClass($call) === 'QueryBuilder' && $this->methodName($call) === 'for') {
                $model ??= $this->modelFrom($call);
            }
        }

        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $finder->findInstanceOf($method, Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $name = $call->name->toString();
            $arguments = $this->arguments($call);

            match (true) {
                $name === 'allowedFilters' => $filters = [...$filters, ...$this->filters($arguments)],
                $name === 'allowedSorts' => $sorts = [...$sorts, ...$this->sorts($arguments)],
                $name === 'allowedIncludes' => $includes = [...$includes, ...$this->includes($arguments)],
                $name === 'allowedFields' => $fields = [...$fields, ...$this->fields($arguments)],
                $name === 'defaultSort' || $name === 'defaultSorts' => $defaultSort ??= $this->string($arguments[0] ?? null),
                isset(self::PAGINATION[$name]) => $pagination ??= $this->pagination($name, $arguments),
                default => null,
            };
        }

        $definition = new QueryDefinition(
            model: $model,
            filters: $filters,
            sorts: $this->withDefaultSort($sorts, $defaultSort),
            includes: $includes,
            fields: $fields,
            pagination: $pagination,
            origin: 'chain',
        );

        return $definition->isEmpty() ? null : $definition;
    }

    /**
     * @param  list<AllowSort>  $sorts
     * @return list<AllowSort>
     */
    protected function withDefaultSort(array $sorts, ?string $defaultSort): array
    {
        if ($defaultSort === null) {
            return $sorts;
        }

        $field = ltrim($defaultSort, '-');
        $direction = str_starts_with($defaultSort, '-') ? 'desc' : 'asc';

        $applied = array_map(
            fn (AllowSort $sort): AllowSort => $sort->name === $field
                ? new AllowSort(name: $sort->name, default: $direction, column: $sort->column, using: $sort->using)
                : $sort,
            $sorts,
        );

        // defaultSort() may name a field that allowedSorts() never listed,
        // which is legal in Spatie: the default applies, the agent just cannot
        // ask for it.
        foreach ($applied as $sort) {
            if ($sort->name === $field) {
                return $applied;
            }
        }

        return [...$applied, new AllowSort(name: $field, default: $direction)];
    }

    /**
     * @param  list<Node\Expr>  $arguments
     * @return list<AllowFilter>
     */
    protected function filters(array $arguments): array
    {
        $filters = [];

        foreach ($arguments as $argument) {
            $literal = $this->string($argument);

            // A bare string is a partial filter, which is what Spatie makes of
            // one in allowedFilters().
            if ($literal !== null) {
                $filters[] = new AllowFilter(name: $literal);

                continue;
            }

            $call = $this->factory($argument, 'AllowedFilter');

            if ($call === null) {
                continue;
            }

            [$method, $args] = $call;
            $type = self::FILTERS[$method] ?? null;

            if ($type === null) {
                continue;
            }

            $name = $this->string($args[0] ?? null) ?? ($type === 'trashed' ? 'trashed' : null);

            if ($name === null) {
                continue;
            }

            $filters[] = new AllowFilter(
                name: $name,
                type: $type,
                column: $type === 'callback' || $type === 'custom' ? null : $this->string($args[1] ?? null),
            );
        }

        return $filters;
    }

    /**
     * @param  list<Node\Expr>  $arguments
     * @return list<AllowSort>
     */
    protected function sorts(array $arguments): array
    {
        $sorts = [];

        foreach ($arguments as $argument) {
            $literal = $this->string($argument);

            if ($literal !== null) {
                $sorts[] = new AllowSort(name: ltrim($literal, '-'));

                continue;
            }

            $call = $this->factory($argument, 'AllowedSort');

            if ($call === null) {
                continue;
            }

            [$method, $args] = $call;
            $name = $this->string($args[0] ?? null);

            if ($name === null) {
                continue;
            }

            $sorts[] = new AllowSort(
                name: ltrim($name, '-'),
                column: $method === 'field' ? $this->string($args[1] ?? null) : null,
                using: $method === 'field' ? null : $this->newClass($args[1] ?? null),
            );
        }

        return $sorts;
    }

    /**
     * @param  list<Node\Expr>  $arguments
     * @return list<AllowInclude>
     */
    protected function includes(array $arguments): array
    {
        $includes = [];

        foreach ($arguments as $argument) {
            $literal = $this->string($argument);

            if ($literal !== null) {
                $includes[] = new AllowInclude(names: $literal);

                continue;
            }

            $call = $this->factory($argument, 'AllowedInclude');

            if ($call === null) {
                continue;
            }

            [$method, $args] = $call;
            $type = self::INCLUDES[$method] ?? null;
            $name = $this->string($args[0] ?? null);

            if ($type === null || $name === null) {
                continue;
            }

            $aggregate = in_array($type, ['min', 'max', 'sum', 'avg'], true);

            $includes[] = new AllowInclude(
                names: $name,
                type: $type,
                relation: $aggregate ? $this->string($args[1] ?? null) : null,
                column: $aggregate ? $this->string($args[2] ?? null) : null,
            );
        }

        return $includes;
    }

    /**
     * Spatie takes `id`, `title` and `lessons.title`; the dotted ones belong to
     * the related resource, which is the `fields[...]` key the agent uses.
     *
     * @param  list<Node\Expr>  $arguments
     * @return array<string, list<string>>
     */
    protected function fields(array $arguments): array
    {
        $fields = [];

        foreach ($arguments as $argument) {
            $literal = $this->string($argument);

            if ($literal === null) {
                continue;
            }

            [$resource, $field] = str_contains($literal, '.')
                ? explode('.', $literal, 2)
                : ['*', $literal];

            $fields[$resource][] = $field;
        }

        return $fields;
    }

    /** @param list<Node\Expr> $arguments */
    protected function pagination(string $method, array $arguments): Paginate
    {
        $perPage = $arguments[0] ?? null;
        $literal = $perPage instanceof Node\Scalar\Int_;

        // ->paginate(15) ignores per_page entirely, so the agent is told the
        // page size rather than handed a knob that does nothing.
        return new Paginate(
            perPage: $literal ? $perPage->value : 15,
            type: self::PAGINATION[$method] ?? 'length-aware',
            fixed: $literal,
        );
    }

    /**
     * Flattens the call's arguments, since every allowed* method is variadic
     * but is just as often handed one array.
     *
     * @return list<Node\Expr>
     */
    protected function arguments(Node\Expr\MethodCall $call): array
    {
        $arguments = [];

        foreach ($call->args as $argument) {
            if (! $argument instanceof Node\Arg) {
                continue;
            }

            if (! $argument->value instanceof Node\Expr\Array_) {
                $arguments[] = $argument->value;

                continue;
            }

            foreach ($argument->value->items as $item) {
                $arguments[] = $item->value;
            }
        }

        return $arguments;
    }

    /**
     * A static call to one of Spatie's factories, as [method, arguments].
     *
     * @return array{0: string, 1: list<Node\Expr|null>}|null
     */
    protected function factory(Node\Expr $node, string $class): ?array
    {
        if (! $node instanceof Node\Expr\StaticCall || $this->shortClass($node) !== $class) {
            return null;
        }

        $method = $this->methodName($node);

        if ($method === null) {
            return null;
        }

        $arguments = [];

        foreach ($node->args as $argument) {
            $arguments[] = $argument instanceof Node\Arg ? $argument->value : null;
        }

        return [$method, $arguments];
    }

    /**
     * Matched on the short name: the chain may import Spatie's classes, fully
     * qualify them or extend them, and all three mean the same thing.
     */
    protected function shortClass(Node\Expr\StaticCall $call): ?string
    {
        return $call->class instanceof Node\Name ? $call->class->getLast() : null;
    }

    protected function methodName(Node\Expr\StaticCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? $call->name->toString() : null;
    }

    /** @return class-string|null */
    protected function modelFrom(Node\Expr\StaticCall $call): ?string
    {
        $argument = $call->args[0] ?? null;
        $value = $argument instanceof Node\Arg ? $argument->value : null;

        // QueryBuilder::for(Course::class) and ::for(Course::query()) both name
        // the model in the same place.
        $name = match (true) {
            $value instanceof Node\Expr\ClassConstFetch => $value->class,
            $value instanceof Node\Expr\StaticCall => $value->class,
            default => null,
        };

        if (! $name instanceof Node\Name) {
            return null;
        }

        $resolved = $name->toString();

        return class_exists($resolved) ? $resolved : null;
    }

    protected function string(?Node $node): ?string
    {
        return $node instanceof Node\Scalar\String_ ? $node->value : null;
    }

    /**
     * The class behind `AllowedSort::custom('x', new TitleSort)`, so a custom
     * sort keeps the name of what implements it.
     *
     * @return class-string|null
     */
    protected function newClass(?Node $node): ?string
    {
        if (! $node instanceof Node\Expr\New_ || ! $node->class instanceof Node\Name) {
            return null;
        }

        $resolved = $node->class->toString();

        return class_exists($resolved) ? $resolved : null;
    }
}
