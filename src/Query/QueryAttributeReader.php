<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Query;

use KayedSpace\McpBridge\Attributes\AllowFields;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\Paginate;
use KayedSpace\McpBridge\Attributes\QueryModel;
use KayedSpace\McpBridge\Contracts\ReadsQueryDefinitions;
use KayedSpace\McpBridge\Support\ReadsAttributes;
use ReflectionClass;
use ReflectionMethod;

/**
 * Query attributes on the method, the controller class or the model.
 *
 * Read in that reverse order so the most specific declaration wins per name:
 * the model carries defaults every endpoint for it shares, the class carries
 * the controller's, and the method has the last word.
 */
class QueryAttributeReader implements ReadsQueryDefinitions
{
    use ReadsAttributes;

    /** @param class-string $class */
    public function read(string $class, ?string $method = null): ?QueryDefinition
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        $action = $method !== null && $reflection->hasMethod($method)
            ? $reflection->getMethod($method)
            : null;

        $model = $this->model($reflection, $action);

        // Model first, so a controller or an action can override any of it.
        $targets = array_values(array_filter([
            $model === null || ! class_exists($model->model) ? null : new ReflectionClass($model->model),
            $reflection,
            $action,
        ]));

        return $this->collect($targets, $model);
    }

    /**
     * @param  list<ReflectionClass<object>|ReflectionMethod>  $targets
     */
    protected function collect(array $targets, ?QueryModel $model): ?QueryDefinition
    {
        $filters = [];
        $sorts = [];
        $includes = [];
        $fields = [];
        $pagination = null;

        foreach ($targets as $target) {
            foreach ($this->many($target, AllowFilter::class) as $filter) {
                $filters[$filter->name] = $filter;
            }

            foreach ($this->many($target, AllowSort::class) as $sort) {
                $sorts[$sort->name] = $sort;
            }

            // Split by name, so one declaration listing several includes can
            // still be overridden one include at a time.
            foreach ($this->many($target, AllowInclude::class) as $include) {
                foreach ($include->names() as $name) {
                    $includes[$name] = $include->named($name);
                }
            }

            foreach ($this->many($target, AllowFields::class) as $declared) {
                $fields[$declared->resource ?? '*'] = $declared->fields();
            }

            $pagination = $this->one($target, Paginate::class) ?? $pagination;
        }

        $definition = new QueryDefinition(
            model: $model?->model,
            resource: $model?->resource,
            filters: array_values($filters),
            sorts: array_values($sorts),
            includes: array_values($includes),
            fields: $fields,
            pagination: $pagination,
        );

        // Nothing declared is not an empty definition: it means this reader has
        // no opinion, and the next one should be asked.
        return $definition->isEmpty() && $model === null ? null : $definition;
    }

    /** @param ReflectionClass<object> $class */
    protected function model(ReflectionClass $class, ?ReflectionMethod $action): ?QueryModel
    {
        return ($action === null ? null : $this->one($action, QueryModel::class))
            ?? $this->one($class, QueryModel::class);
    }
}
