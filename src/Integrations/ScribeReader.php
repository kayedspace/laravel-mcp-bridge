<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Integrations;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Contracts\ReadsDocumentation;
use KayedSpace\McpBridge\Data\Documented;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use ReflectionClass;
use ReflectionMethod;

/**
 * Scribe's docblock annotations, read as declared schema.
 *
 * Scribe documents parameters in the docblock rather than in attributes, so
 * this reads the text:
 *
 *     @queryParam per_page integer Results per page. Example: 15
 *
 *     @bodyParam  title    string  required The course title.
 *
 *     @urlParam   course   integer required The course id.
 *
 * Nothing here needs Scribe to be installed, because the annotations are the
 * declaration and they are already in the source.
 */
class ScribeReader implements ReadsDocumentation
{
    /** Scribe's parameter annotations, mapped to where the value travels. */
    protected const PARAMETERS = [
        'queryParam' => WireIn::Query,
        'bodyParam' => WireIn::Body,
        'urlParam' => WireIn::Path,
    ];

    public function __construct(protected Repository $config) {}

    /** @param class-string $class */
    public function read(string $class, ?string $method = null): ?Documented
    {
        if (! $this->config->boolean('mcp-bridge.integrations.scribe.enabled', true) || ! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        $action = $method !== null && $reflection->hasMethod($method)
            ? $reflection->getMethod($method)
            : null;

        $documented = $this->fromDocBlock($this->docBlock($reflection), group: true);

        if ($action !== null) {
            $documented = $documented->mergedWith($this->fromDocBlock($this->docBlock($action)));
        }

        return $documented->isEmpty() ? null : $documented;
    }

    /**
     * @param  list<string>  $lines
     * @param  bool  $group  a controller's @group is the group every action in it belongs to
     */
    protected function fromDocBlock(array $lines, bool $group = false): Documented
    {
        $schema = InputSchema::empty();
        $tags = [];

        foreach ($lines as $line) {
            if (! str_starts_with($line, '@')) {
                continue;
            }

            [$tag, $rest] = $this->split(substr($line, 1));
            $in = self::PARAMETERS[$tag] ?? null;

            if ($in !== null) {
                $field = $this->field($rest, $in);

                $schema = $field === null ? $schema : $schema->with($field);

                continue;
            }

            $tags[$tag] = $rest;
        }

        $hidden = isset($tags['hideFromAPIDocumentation']) || isset($tags['hideFromApiDocumentation']);

        return new Documented(
            group: $group ? ($tags['group'] ?? null) : null,
            excluded: $hidden && $this->config->boolean('mcp-bridge.discovery.respect_docs_exclusions', true),
            input: $schema,
        );
    }

    /**
     * `name type [required] description [Example: value]`, which is the shape
     * Scribe documents and the shape its own parser expects.
     */
    protected function field(string $definition, WireIn $in): ?Field
    {
        [$name, $rest] = $this->split($definition);

        if ($name === '') {
            return null;
        }

        [$type, $rest] = $this->split($rest);

        $required = false;

        if (str_starts_with($rest, 'required')) {
            $required = true;
            $rest = trim(substr($rest, strlen('required')));
        }

        [$description, $example] = $this->example($rest);

        $path = str_replace(['[]', '[', ']'], ['.*', '.', ''], $name);

        return new Field(
            path: $path,
            type: $this->type($type),
            required: $required || $in === WireIn::Path,
            description: $description === '' ? null : $description,
            examples: $example === null ? [] : [$example],
            wire: new Wire(in: $in, name: $name),
            origin: ['scribe:'.$in->value],
        );
    }

    /** @return array{0: string, 1: string|null} */
    protected function example(string $rest): array
    {
        if (preg_match('/\bExample:\s*(.*)$/', $rest, $matches) !== 1) {
            return [trim($rest), null];
        }

        $example = trim($matches[1]);

        return [
            trim(substr($rest, 0, (int) strpos($rest, 'Example:'))),
            $example === '' || $example === 'no-example' ? null : $example,
        ];
    }

    /** Scribe writes a list as `string[]`, so the brackets decide before the name does. */
    protected function type(string $type): FieldType
    {
        return str_ends_with($type, '[]')
            ? FieldType::Array
            : FieldType::fromName($type);
    }

    /**
     * The docblock as trimmed lines, with the comment syntax taken off.
     *
     * @param  ReflectionClass<object>|ReflectionMethod  $target
     * @return list<string>
     */
    protected function docBlock(ReflectionClass|ReflectionMethod $target): array
    {
        $comment = $target->getDocComment();

        if ($comment === false) {
            return [];
        }

        $lines = preg_split('/\R/', $comment);

        if ($lines === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line): string => trim(ltrim(trim($line), '/*')),
            $lines,
        ), fn (string $line): bool => $line !== ''));
    }

    /** @return array{0: string, 1: string} */
    protected function split(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line), 2);

        return [$parts[0] ?? '', trim($parts[1] ?? '')];
    }
}
