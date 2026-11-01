<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Integrations;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\ExcludeAllRoutesFromDocs;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Dedoc\Scramble\Attributes\MissingValue;
use Dedoc\Scramble\Attributes\Parameter;
use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Contracts\ReadsDocumentation;
use KayedSpace\McpBridge\Data\Documented;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Support\ReadsAttributes;
use ReflectionClass;
use ReflectionMethod;

/**
 * dedoc/scramble's attributes, read as declared schema.
 *
 * Scramble's own classes are consumed by fully-qualified name and never
 * redeclared: `QueryParameter` stays Scramble's, because two classes sharing
 * one short name would mean a controller importing either one looks identical
 * and behaves differently.
 *
 * Every attribute is read through Scramble's `Parameter` base class, so the
 * four parameter kinds are found in one pass and a new one of theirs arrives
 * for free. Nothing here is loaded unless Scramble is installed.
 */
class ScrambleReader implements ReadsDocumentation
{
    use ReadsAttributes;

    public function __construct(protected Repository $config) {}

    /** @param class-string $class */
    public function read(string $class, ?string $method = null): ?Documented
    {
        if (! $this->enabled() || ! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        $action = $method !== null && $reflection->hasMethod($method)
            ? $reflection->getMethod($method)
            : null;

        $documented = $this->fromClass($reflection);

        if ($action !== null) {
            $documented = $documented->mergedWith($this->fromMethod($action));
        }

        return $documented->isEmpty() ? null : $documented;
    }

    protected function enabled(): bool
    {
        // The reader costs nothing when Scramble is absent, and a team can
        // still turn it off, for instance while its docs are mid-rewrite.
        return class_exists(Parameter::class)
            && $this->config->boolean('mcp-bridge.integrations.scramble.enabled', true);
    }

    /** @param ReflectionClass<object> $class */
    protected function fromClass(ReflectionClass $class): Documented
    {
        return new Documented(
            group: $this->one($class, Group::class)?->name,
            excluded: $this->respectsExclusions()
                && $this->one($class, ExcludeAllRoutesFromDocs::class) !== null,
            input: $this->parameters($class),
            hidden: $this->ignored($class),
        );
    }

    protected function fromMethod(ReflectionMethod $method): Documented
    {
        $endpoint = $this->one($method, Endpoint::class);

        return new Documented(
            title: $endpoint?->title,
            description: $endpoint?->description,
            group: $this->one($method, Group::class)?->name,
            excluded: $this->respectsExclusions()
                && $this->one($method, ExcludeRouteFromDocs::class) !== null,
            input: $this->parameters($method),
            hidden: $this->ignored($method),
        );
    }

    /** @param ReflectionClass<object>|ReflectionMethod $target */
    protected function parameters(ReflectionClass|ReflectionMethod $target): InputSchema
    {
        $schema = InputSchema::empty();

        foreach ($this->many($target, Parameter::class) as $parameter) {
            $field = $this->field($parameter);

            if ($field !== null) {
                $schema = $schema->with($field);
            }
        }

        return $schema;
    }

    protected function field(Parameter $parameter): ?Field
    {
        $in = $this->wireIn($parameter->in);

        if ($in === null) {
            return null;
        }

        $path = $this->path($parameter->name);
        $default = $parameter->default instanceof MissingValue ? null : $parameter->default;
        $example = $parameter->example instanceof MissingValue ? null : $parameter->example;

        return new Field(
            path: $path,
            type: FieldType::fromName($parameter->type),
            required: $parameter->required,
            description: $this->description($parameter),
            format: $parameter->format,
            default: $default,
            examples: $example === null ? [] : [$example],
            wire: new Wire(in: $in, name: $parameter->name),
            origin: ['scramble:'.$parameter->in],
        );
    }

    /**
     * A deprecated parameter is still accepted, so it is still an input; the
     * agent is simply told not to reach for it.
     */
    protected function description(Parameter $parameter): ?string
    {
        if (! $parameter->deprecated) {
            return $parameter->description;
        }

        return trim(($parameter->description ?? '').' Deprecated, avoid using it.');
    }

    /**
     * Headers and cookies are transport concerns, and an agent should not be
     * setting them, so a documented header is an input only where the
     * application asks for it. A cookie never is: there is no case where an
     * agent choosing a cookie value is the right answer.
     */
    protected function wireIn(string $in): ?WireIn
    {
        return match ($in) {
            'query' => WireIn::Query,
            'body' => WireIn::Body,
            'path' => WireIn::Path,
            'header' => $this->config->boolean('mcp-bridge.integrations.scramble.read_headers', false)
                ? WireIn::Header
                : null,
            default => null,
        };
    }

    /**
     * Bracket notation becomes nesting: `filter[author]` is a `filter` object
     * with an `author` property, which is the shape the executor serialises
     * back into `filter[author]=...`. An empty pair of brackets
     * means a list, which the schema writes as a wildcard member.
     */
    protected function path(string $name): string
    {
        $path = (string) preg_replace('/\[\s*\]/', '[*]', $name);
        $path = (string) preg_replace('/\[([^\]]+)\]/', '.$1', $path);

        return trim($path, '.');
    }

    /**
     * @param  ReflectionClass<object>|ReflectionMethod  $target
     * @return list<string>
     */
    protected function ignored(ReflectionClass|ReflectionMethod $target): array
    {
        return array_map(
            fn (IgnoreParam $ignored): string => $this->path($ignored->name),
            $this->many($target, IgnoreParam::class),
        );
    }

    protected function respectsExclusions(): bool
    {
        return $this->config->boolean('mcp-bridge.discovery.respect_docs_exclusions', true);
    }
}
