<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Query\ModelHints;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * A parameter list as tool inputs, for the sources whose unit of work is a
 * class: a job's constructor, an action's handle().
 *
 * A type-hinted signature is already a schema. What it cannot express is
 * refused rather than guessed at: an untyped parameter, a closure, an
 * interface that is not a model. Those cannot be built from JSON, so a tool
 * that needs one would fail at its first call, and failing at compile time
 * with the parameter named is strictly better.
 *
 * A parameter typed as a spatie/laravel-data object is the exception that
 * proves it: the object declares its own rules, so its fields become the
 * tool's inputs, flat, and the executor builds the object from them.
 */
class ParameterMapper
{
    public function __construct(
        protected RuleMapper $rules,
        protected FormRequestReader $requests,
    ) {}

    /** @param class-string $class */
    public function forClass(string $class): ParameterSchema
    {
        if (! class_exists($class)) {
            return new ParameterSchema(InputSchema::empty(), ['the class does not exist']);
        }

        $constructor = (new ReflectionClass($class))->getConstructor();

        return $constructor === null
            ? new ParameterSchema(InputSchema::empty())
            : $this->read($constructor);
    }

    /**
     * @param  class-string  $class
     */
    public function forMethod(string $class, string $method): ParameterSchema
    {
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return new ParameterSchema(InputSchema::empty());
        }

        return $this->read(new ReflectionMethod($class, $method));
    }

    protected function read(ReflectionFunctionAbstract $function): ParameterSchema
    {
        $schema = InputSchema::empty();
        $unsupported = [];
        $models = [];
        $coerce = [];
        $data = [];
        $position = 0;

        foreach ($function->getParameters() as $parameter) {
            $reason = $this->reject($parameter);

            if ($reason !== null) {
                $unsupported[] = "{$parameter->getName()}: {$reason}";

                continue;
            }

            $object = $this->dataClass($parameter);

            if ($object !== null) {
                $data[$parameter->getName()] = $object;
                $schema = $schema->with(...$this->dataFields($object));
                $position++;

                continue;
            }

            $model = $this->modelClass($parameter);

            if ($model !== null) {
                $models[$parameter->getName()] = $model;
            }

            $object = $model === null ? $this->objectClass($parameter) : null;

            if ($object !== null) {
                $coerce[$parameter->getName()] = $object;
            }

            $schema = $schema->with(...$this->field($parameter, $position++, $model));
        }

        return new ParameterSchema($schema->sorted(), $unsupported, $models, $coerce, $data);
    }

    /**
     * The object's own rules as fields, in the same wire as every other
     * constructor input, so the agent sees `title` and `status` rather than
     * a `data` it would have to guess the shape of.
     *
     * @param  class-string<Data>  $data
     * @return list<Field>
     */
    protected function dataFields(string $data): array
    {
        $fields = [];

        foreach ($this->requests->rulesFor($data) as $path => $rule) {
            $fields[] = $this->rules->map($path, $rule, WireIn::Constructor)
                ->with(origin: ['rules', 'data:'.class_basename($data)]);
        }

        return $fields;
    }

    /** @return class-string<Data>|null */
    protected function dataClass(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return FormRequestReader::isData($name) ? $name : null;
    }

    /**
     * @param  class-string<Model>|null  $model
     * @return list<Field>
     */
    protected function field(ReflectionParameter $parameter, int $position, ?string $model): array
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        $wire = new Wire(in: WireIn::Constructor, name: $name, position: $position);

        $required = ! $parameter->isOptional();
        $default = $parameter->isDefaultValueAvailable() ? $this->scalarDefault($parameter) : null;

        if ($model !== null) {
            return [new Field(
                path: $name,
                type: $this->keyType($model),
                required: $required,
                description: 'The id of the '.class_basename($model).' to use.',
                wire: $wire,
                origin: ['constructor:model'],
            )];
        }

        $named = $type instanceof ReflectionNamedType ? $type->getName() : 'string';

        if ($named === 'array') {
            return [
                new Field(
                    path: $name,
                    type: FieldType::Array,
                    required: $required,
                    default: $default,
                    wire: $wire,
                    origin: ['constructor'],
                ),
                new Field(path: $name.'.*', type: FieldType::String, origin: ['constructor']),
            ];
        }

        return [new Field(
            path: $name,
            type: $this->type($named),
            required: $required,
            nullable: $type?->allowsNull() ?? false,
            format: $this->format($named),
            enum: enum_exists($named) ? ModelHints::valuesOf($named) : [],
            default: $default,
            wire: $wire,
            origin: ['constructor'],
        )];
    }

    /**
     * Why this parameter cannot come from an agent, or null when it can.
     */
    protected function reject(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if ($type === null) {
            return 'it has no type, so there is nothing to build from JSON';
        }

        if (! $type instanceof ReflectionNamedType) {
            return 'its type is a union or intersection, which an agent cannot choose between';
        }

        if ($type->isBuiltin()) {
            return in_array($type->getName(), ['int', 'float', 'string', 'bool', 'array'], true)
                ? null
                : "[{$type->getName()}] cannot be sent as JSON";
        }

        $name = $type->getName();

        // A class built on a package that is not installed throws while it
        // autoloads. The parameter cannot be described, so the tool is
        // refused for it, and the rest of the build stands.
        try {
            class_exists($name);
        } catch (Throwable) {
            return "[{$name}] cannot be loaded, so there is nothing to build from JSON";
        }

        if (is_subclass_of($name, Model::class) || is_subclass_of($name, DateTimeInterface::class)) {
            return null;
        }

        if (is_subclass_of($name, BackedEnum::class) || FormRequestReader::isData($name)) {
            return null;
        }

        // A pure enum has no value an agent could send: its cases are names in
        // PHP source, not data.
        if (enum_exists($name)) {
            return "[{$name}] is an enum without backing values, so there is nothing to send";
        }

        return "[{$name}] is not something an agent can send";
    }

    /**
     * An enum or a date arrives from the agent as a string, so the executor
     * has to turn it into the thing the constructor declared.
     *
     * @return class-string|null
     */
    protected function objectClass(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return is_subclass_of($name, BackedEnum::class)
            || is_subclass_of($name, DateTimeInterface::class)
            || $name === DateTimeInterface::class
                ? $name
                : null;
    }

    /** @return class-string<Model>|null */
    protected function modelClass(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return is_subclass_of($name, Model::class) ? $name : null;
    }

    /** @param class-string<Model> $model */
    protected function keyType(string $model): FieldType
    {
        $instance = new $model;

        return $instance->getKeyType() === 'int' ? FieldType::Integer : FieldType::String;
    }

    protected function type(string $named): FieldType
    {
        // A backed enum arrives as its value, which is a string as far as the
        // schema is concerned; the cases themselves become the enum list.
        return enum_exists($named) ? FieldType::String : FieldType::fromName($named);
    }

    protected function format(string $named): ?string
    {
        return is_subclass_of($named, DateTimeInterface::class) || $named === DateTimeInterface::class
            ? 'date-time'
            : null;
    }

    protected function scalarDefault(ReflectionParameter $parameter): mixed
    {
        $default = $parameter->getDefaultValue();

        // An enum default is advertised as the value an agent would send.
        if ($default instanceof BackedEnum) {
            return $default->value;
        }

        return is_scalar($default) || is_array($default) ? $default : null;
    }
}
