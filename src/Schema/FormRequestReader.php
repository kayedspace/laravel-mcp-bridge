<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Reads the rules off the FormRequest a controller action already type-hints,
 * so the agent-facing schema and the validation that will actually run come
 * from one declaration.
 *
 * ponytail: rules() is called on a plain instance rather than parsed
 * statically. It covers the common case in a few lines; a rules() method that
 * depends on request state degrades to no rules instead of throwing,
 * and mcp-bridge:lint will be the place that reports it.
 */
class FormRequestReader
{
    /** @return array<string, string|array<int, mixed>> */
    public function rules(Route $route): array
    {
        $class = $this->formRequest($route);

        return $class === null ? [] : $this->rulesFor($class);
    }

    /**
     * Read the rules of a named request, for an action that validates inline
     * and points at one with #[McpInput].
     *
     * A spatie/laravel-data class is a rules source too: its properties and
     * validation attributes already say what a valid payload is, and it
     * answers through a static method rather than an instance.
     *
     * @param  class-string  $class
     * @return array<string, string|array<int, mixed>>
     */
    public function rulesFor(string $class): array
    {
        try {
            if (self::isData($class)) {
                // With a key for every property, because the resolver skips
                // a property that has a default and is absent from the
                // payload, and a schema has to describe the optional fields
                // as well as the required ones.
                return $this->normalise($class::getValidationRules($this->emptyPayload($class)));
            }

            // Deliberately not resolved through the container: Laravel hooks
            // FormRequest resolution to run validateResolved(), which would
            // try to validate an empty request just to read its rules.
            $request = new $class;

            if (! method_exists($request, 'rules')) {
                return [];
            }

            $rules = $request->rules();
        } catch (Throwable) {
            return [];
        }

        return is_array($rules) ? $this->normalise($rules) : [];
    }

    /**
     * Whether a class is a spatie/laravel-data object, false when that
     * package is not installed.
     *
     * @phpstan-assert-if-true class-string<Data> $class
     */
    public static function isData(string $class): bool
    {
        return class_exists(Data::class) && is_subclass_of($class, Data::class);
    }

    /**
     * Every public property of a data object, as a payload of nulls.
     *
     * @param  class-string<Data>  $class
     * @return array<string, null>
     */
    protected function emptyPayload(string $class): array
    {
        $payload = [];

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic()) {
                $payload[$property->getName()] = null;
            }
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @return array<string, string|array<int, mixed>>
     */
    protected function normalise(array $rules): array
    {
        $normalised = [];

        foreach ($rules as $path => $rule) {
            if (! is_string($path)) {
                continue;
            }

            if (is_string($rule)) {
                $normalised[$path] = $rule;
            } elseif (is_array($rule)) {
                $normalised[$path] = array_values($rule);
            }
        }

        return $normalised;
    }

    /** @return class-string<FormRequest>|null */
    protected function formRequest(Route $route): ?string
    {
        try {
            $parameters = $route->signatureParameters(['subClass' => FormRequest::class]);
        } catch (Throwable) {
            return null;
        }

        foreach ($parameters as $parameter) {
            if (! $parameter instanceof ReflectionParameter) {
                continue;
            }

            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                /** @var class-string<FormRequest> $class */
                $class = $type->getName();

                return $class;
            }
        }

        return null;
    }
}
