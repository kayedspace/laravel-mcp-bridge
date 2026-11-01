<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Contracts\ReadsDocumentation;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\Documented;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\RouteTarget;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Discovery\AttributeReader;
use KayedSpace\McpBridge\Discovery\Declarations;
use KayedSpace\McpBridge\Discovery\RouteFilter;
use KayedSpace\McpBridge\Discovery\ToolNamer;
use KayedSpace\McpBridge\Schema\SchemaBuilder;
use KayedSpace\McpBridge\Security\PolicyRequirement;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * HTTP routes as tools. The only source whose exposure follows the configured
 * mode, because a route arrives already wrapped in middleware, a guard, a
 * FormRequest and usually a policy, and a tool call inherits all of it.
 */
class RouteSource implements Source
{
    public function __construct(
        protected Router $router,
        protected RouteFilter $filter,
        protected ToolNamer $namer,
        protected AttributeReader $attributes,
        protected ReadsDocumentation $documentation,
        protected SchemaBuilder $schema,
        protected ExecutesTools $executor,
        protected Repository $config,
        protected PolicyRequirement $policies,
    ) {}

    public function key(): string
    {
        return 'route';
    }

    public function discover(): iterable
    {
        $candidates = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            foreach ($this->filter->methodsFor($route) as $method) {
                $candidate = $this->candidate($route, $method);

                $candidates[$candidate->id] = $candidate;
            }
        }

        // Sorted so that an unchanged application always compiles to an
        // identical manifest, whatever order the router hands routes back in.
        ksort($candidates);

        return array_values($candidates);
    }

    public function schema(ToolCandidate $candidate): InputSchema
    {
        $route = $this->route($candidate);

        if ($route === null) {
            return InputSchema::empty();
        }

        return $this->schema->forRoute($route, $candidate);
    }

    public function validate(ToolCandidate $candidate): array
    {
        if ($candidate->meta['ignored'] ?? false) {
            return [Refusal::ignored($candidate->id)];
        }

        $policy = $this->policyRefusals($candidate);

        if ($policy !== []) {
            return $policy;
        }

        if ($candidate->meta['opted_in'] ?? false) {
            return [];
        }

        $mode = $this->config->string('mcp-bridge.mode', 'opt-in');

        $exposed = match ($mode) {
            'opt-out' => true,
            'read-only' => $candidate->safety?->readOnly === true,
            default => false,
        };

        return $exposed ? [] : [Refusal::notExposed($candidate->id, $mode)];
    }

    /**
     * Refuse a write whose model has no policy, when the application asked
     * for that.
     *
     * Stricter than Laravel's own default, where a route without a policy is
     * simply a route without a policy and the controller is trusted to have
     * thought about it. An endpoint agents reach is worth holding to a higher
     * bar than one a developer wrote a form for, and this is the switch for
     * saying so.
     *
     * Reads only bound models: a route that binds nothing has no model for a
     * policy to be missing from, and inventing one from the URI would refuse
     * tools over a guess.
     *
     * @return list<Refusal>
     */
    protected function policyRefusals(ToolCandidate $candidate): array
    {
        if (! $this->config->boolean('mcp-bridge.security.require_policy', false)) {
            return [];
        }

        if ($candidate->safety?->readOnly === true) {
            return [];
        }

        $route = $this->route($candidate);

        if ($route === null) {
            return [];
        }

        foreach ($this->boundModels($route) as $model) {
            $refusal = $this->policies->missingFor($model);

            if ($refusal !== null) {
                return [$refusal];
            }
        }

        return [];
    }

    /**
     * The models this route binds, by the same reflection the schema uses to
     * type them.
     *
     * @return list<class-string<Model>>
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
            $type = $parameter instanceof ReflectionParameter ? $parameter->getType() : null;
            $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if (is_string($name) && is_subclass_of($name, Model::class)) {
                $models[] = $name;
            }
        }

        return $models;
    }

    public function execute(ToolCall $call): SourceResult
    {
        return $this->executor->execute($call);
    }

    protected function candidate(Route $route, string $method): ToolCandidate
    {
        [$class, $action] = $this->action($route);

        $declarations = $class === null
            ? Declarations::none()
            : $this->attributes->read($class, $action);

        // What the API documentation already says, used only where this
        // package's own attributes say nothing.
        $documented = $class === null
            ? null
            : $this->documentation->read($class, $action);

        return new ToolCandidate(
            source: $this->key(),
            id: $this->id($route, $method),
            target: new RouteTarget($method, $route->uri(), $route->getName()),
            suggestedName: $this->namer->forRoute($route, $method, $declarations->tool?->name),
            // ?? short-circuits the whole left side, so the plain arrow is
            // safe here even though #[McpTool] may be absent.
            title: $declarations->tool->title ?? $documented?->title,
            description: $declarations->tool->description ?? $documented?->description,
            groups: $this->groups($declarations, $documented),
            safety: $this->safety($method)->with($declarations->safety),
            declarations: $declarations->raw,
            meta: [
                'opted_in' => $declarations->optedIn(),
                'ignored' => $declarations->ignored || $documented?->excluded === true,
                'controller' => $class,
                'action' => $action,
            ],
            declared: $declarations->toDeclared(),
        );
    }

    /**
     * A documentation group names the tool's group when this package's own
     * grouping does not, so an app that already organises its docs gets the
     * same organisation for its tools.
     *
     * @return list<string>
     */
    protected function groups(Declarations $declarations, ?Documented $documented): array
    {
        if ($declarations->groups !== []) {
            return $declarations->groups;
        }

        return $documented?->group === null ? [] : [$documented->group];
    }

    /** @return array{0: class-string|null, 1: string|null} */
    protected function action(Route $route): array
    {
        $uses = $route->getActionName();

        if ($uses === '' || $uses === 'Closure') {
            return [null, null];
        }

        if (! Str::contains($uses, '@')) {
            /** @var class-string $uses */
            return [$uses, '__invoke'];
        }

        [$class, $method] = explode('@', $uses, 2);

        /** @var class-string $class */
        return [$class, $method];
    }

    protected function id(Route $route, string $method): string
    {
        $name = $route->getName();

        return $name === null || $name === ''
            ? "route:{$method} {$route->uri()}"
            : "route:{$name}:{$method}";
    }

    /** Inferred from the HTTP method, and overridden later by attributes. */
    protected function safety(string $method): Safety
    {
        return Safety::fromHttpMethod($method);
    }

    protected function route(ToolCandidate $candidate): ?Route
    {
        $target = $candidate->target;

        if (! $target instanceof RouteTarget) {
            return null;
        }

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $target->uri && in_array($target->method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
