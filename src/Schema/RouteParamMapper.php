<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Route parameters as inputs, typed from route model binding and from the
 * route's own constraints.
 */
class RouteParamMapper
{
    public function map(Route $route): InputSchema
    {
        $schema = InputSchema::empty();
        $models = $this->boundModels($route);

        foreach ($route->parameterNames() as $name) {
            if (! is_string($name)) {
                continue;
            }

            $schema = $schema->with($this->field($route, $name, $models[$name] ?? null));
        }

        return $schema;
    }

    /** @param class-string<Model>|null $model */
    protected function field(Route $route, string $name, ?string $model): Field
    {
        $where = $this->constraint($route, $name);
        $optional = Str::contains($route->uri(), '{'.$name.'?}');

        return new Field(
            path: $name,
            type: $this->type($where, $model),
            required: ! $optional,
            description: $this->description($model),
            pattern: $this->pattern($where),
            enum: $this->enum($where),
            wire: new Wire(in: WireIn::Path, name: $name),
            origin: ['route-param'],
        );
    }

    protected function constraint(Route $route, string $name): ?string
    {
        $where = $route->wheres[$name] ?? null;

        return is_string($where) ? $where : null;
    }

    /** @param class-string<Model>|null $model */
    protected function type(?string $where, ?string $model): FieldType
    {
        if ($where === '[0-9]+') {
            return FieldType::Integer;
        }

        if ($model !== null && $this->keyType($model) === 'int') {
            return FieldType::Integer;
        }

        return FieldType::String;
    }

    protected function pattern(?string $where): ?string
    {
        // A numeric constraint is already carried by the type, and an
        // alternation is better expressed as an enum.
        if ($where === null || $where === '[0-9]+' || $this->isAlternation($where)) {
            return null;
        }

        return $where;
    }

    /** @return list<string> */
    protected function enum(?string $where): array
    {
        if ($where === null || ! $this->isAlternation($where)) {
            return [];
        }

        return array_values(array_filter(explode('|', $where)));
    }

    protected function isAlternation(string $where): bool
    {
        return Str::contains($where, '|')
            && preg_match('/^[a-zA-Z0-9_|-]+$/', $where) === 1;
    }

    /** @param class-string<Model>|null $model */
    protected function description(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }

        $subject = Str::lower(Str::headline(class_basename($model)));

        return 'The '.$this->keyName($model)." of the {$subject}.";
    }

    /** @param class-string<Model> $model */
    protected function keyType(string $model): string
    {
        try {
            return (new $model)->getKeyType();
        } catch (Throwable) {
            return 'string';
        }
    }

    /** @param class-string<Model> $model */
    protected function keyName(string $model): string
    {
        try {
            return (new $model)->getRouteKeyName();
        } catch (Throwable) {
            return 'id';
        }
    }

    /**
     * Route parameters whose controller signature binds them to a model.
     *
     * @return array<string, class-string<Model>>
     */
    protected function boundModels(Route $route): array
    {
        try {
            $parameters = $route->signatureParameters(['subClass' => Model::class]);
        } catch (Throwable) {
            return [];
        }

        $models = [];

        foreach ($parameters as $parameter) {
            if (! $parameter instanceof ReflectionParameter) {
                continue;
            }

            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            $class = $type->getName();

            if (is_subclass_of($class, Model::class)) {
                $models[$parameter->getName()] = $class;
            }
        }

        return $models;
    }
}
