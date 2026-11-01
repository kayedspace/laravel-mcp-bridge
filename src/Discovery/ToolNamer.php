<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Discovery;

use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Turns a route into the name an agent sees. First match wins: an explicit
 * name, then the route name, then the HTTP method and URI.
 */
class ToolNamer
{
    public const MAX_LENGTH = 64;

    /** Used only when a route has no name to read a verb from. */
    protected const METHOD_VERBS = [
        'GET' => 'get',
        'POST' => 'create',
        'PUT' => 'update',
        'PATCH' => 'update',
        'DELETE' => 'delete',
    ];

    public function __construct(protected Repository $config) {}

    public function forRoute(Route $route, string $httpMethod, ?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $this->sanitise($explicit);
        }

        $name = $route->getName();

        return $this->sanitise(
            $name === null || $name === ''
                ? $this->fromPath($route->uri(), $httpMethod)
                : $this->fromRouteName($name),
        );
    }

    /**
     * An OpenAPI operation names itself by its operationId when it has one,
     * which is the name the API's own documentation uses, and otherwise by
     * its method and path the way a nameless route does.
     */
    public function forOperation(string $httpMethod, string $path, ?string $operationId, ?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $this->sanitise($explicit);
        }

        if ($operationId !== null && $operationId !== '') {
            return $this->sanitise(Str::snake($operationId));
        }

        return $this->sanitise($this->fromPath($path, $httpMethod));
    }

    /**
     * A command's own name is already a namespace and an action, so it reads
     * as a tool name the same way a route name does: `stats:recalculate`
     * becomes `recalculate_stats`.
     *
     * The namespace is not singularised, unlike a route's resource segment: a
     * command namespace is a subject area (`stats`, `reports`, `ops`), not one
     * record.
     */
    public function forCommand(string $name, ?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $this->sanitise($explicit);
        }

        $segments = explode(':', $name);
        $action = array_pop($segments);
        $verbs = $this->verbs();

        return $this->sanitise($this->assemble(
            $verbs[$action] ?? $action,
            implode('_', $segments),
        ));
    }

    /**
     * A job or action class names itself: SendInvoice becomes send_invoice.
     * The class name is already a verb and a subject in the order a tool name
     * wants them, so nothing is rearranged.
     *
     * @param  class-string  $class
     */
    public function forClass(string $class, ?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $this->sanitise($explicit);
        }

        $base = class_basename($class);

        // A conventional suffix is noise in a tool name: the agent does not
        // care that the work happens to be implemented as a job.
        foreach (['Job', 'Action', 'Command'] as $suffix) {
            if ($base !== $suffix && Str::endsWith($base, $suffix)) {
                $base = Str::beforeLast($base, $suffix);
            }
        }

        return $this->sanitise($base);
    }

    /**
     * A model operation names itself the way a resource route does:
     * list_courses, get_course, create_course.
     *
     * @param  class-string  $class
     */
    public function forModel(string $class, string $operation, ?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $this->sanitise($explicit);
        }

        $verbs = $this->verbs();
        $verb = $verbs[$operation] ?? $operation;
        $plural = $operation === 'list' || $operation === ($verbs['index'] ?? 'list');

        return $this->sanitise($this->assemble(
            $verb,
            $this->resource([Str::snake(class_basename($class))], plural: $plural),
        ));
    }

    /**
     * The configured prefix for a source's tools, in the configured style,
     * so `model` and `get_course` read as one name whatever the style.
     */
    public function prefixed(string $name, string $source): string
    {
        $prefix = $this->config->array('mcp-bridge.naming.source_prefixes', [])[$source] ?? null;

        if (! is_string($prefix) || $prefix === '') {
            return $name;
        }

        return $this->sanitise(Str::snake($prefix).'_'.Str::snake($name));
    }

    protected function fromRouteName(string $routeName): string
    {
        $segments = $this->stripNoise(explode('.', $routeName));

        if ($segments === []) {
            return $routeName;
        }

        $action = array_pop($segments);
        $verbs = $this->verbs();

        // A known resource action names the verb and leaves the chain as the
        // resource; anything else is an action tool, where the segment itself
        // is the verb: courses.publish becomes publish_course.
        $verb = $verbs[$action] ?? $action;
        $isCollection = in_array($action, ['index', $verbs['index'] ?? 'list'], true);

        return $this->assemble($verb, $this->resource($segments, plural: $isCollection));
    }

    protected function fromPath(string $uri, string $httpMethod): string
    {
        $segments = array_values(array_filter(
            explode('/', $uri),
            fn (string $segment): bool => $segment !== '' && ! Str::startsWith($segment, '{'),
        ));

        $verb = self::METHOD_VERBS[$httpMethod] ?? Str::lower($httpMethod);

        return $this->assemble($verb, implode('_', $this->stripNoise($segments)));
    }

    /**
     * Parent segments read as singular and only the subject is pluralised, so
     * courses.lessons.index becomes list_course_lessons.
     *
     * @param  list<string>  $segments
     */
    protected function resource(array $segments, bool $plural): string
    {
        if ($segments === []) {
            return '';
        }

        $subject = array_pop($segments);
        $subject = $plural ? Str::plural($subject) : Str::singular($subject);

        $parents = array_map(fn (string $segment): string => Str::singular($segment), $segments);

        return implode('_', [...$parents, $subject]);
    }

    protected function assemble(string $verb, string $resource): string
    {
        if ($resource === '') {
            return $verb;
        }

        return $this->config->string('mcp-bridge.naming.pattern', '{verb}_{resource}') === '{resource}_{verb}'
            ? "{$resource}_{$verb}"
            : "{$verb}_{$resource}";
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    protected function stripNoise(array $segments): array
    {
        $prefixes = array_values(array_filter(
            $this->config->array('mcp-bridge.discovery.prefixes', []),
            'is_string',
        ));

        $stripVersion = $this->config->boolean('mcp-bridge.discovery.strip_version', true);

        return array_values(array_filter(
            $segments,
            fn (string $segment): bool => ! in_array($segment, $prefixes, true)
                && ! ($stripVersion && preg_match('/^v\d+$/', $segment) === 1),
        ));
    }

    /** @return array<string, string> */
    protected function verbs(): array
    {
        $verbs = [];

        foreach ($this->config->array('mcp-bridge.naming.verbs', []) as $action => $verb) {
            if (is_string($action) && is_string($verb)) {
                $verbs[$action] = $verb;
            }
        }

        return $verbs;
    }

    /** The form an explicit name takes once it is a tool name. */
    public function sanitised(string $name): string
    {
        return $this->sanitise($name);
    }

    protected function sanitise(string $name): string
    {
        $name = Str::snake(Str::replace(['-', '.', '/', '\\'], '_', $name));
        $name = (string) preg_replace('/[^a-z0-9_]+/', '_', Str::lower($name));
        $name = trim((string) preg_replace('/_+/', '_', $name), '_');

        return $this->styled(Str::limit($name, self::MAX_LENGTH, ''));
    }

    /**
     * snake_case by default, because that is what an MCP tool name
     * conventionally looks like. A server whose other tools are camelCase or
     * kebab-case can say so rather than being the odd one out of its own set.
     */
    protected function styled(string $name): string
    {
        return match ($this->config->string('mcp-bridge.naming.style', 'snake')) {
            'camel' => Str::camel($name),
            'kebab' => Str::replace('_', '-', $name),
            default => $name,
        };
    }
}
