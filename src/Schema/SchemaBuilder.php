<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Contracts\ReadsDocumentation;
use KayedSpace\McpBridge\Contracts\ReadsQueryDefinitions;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\RouteTarget;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Discovery\AttributeReader;
use KayedSpace\McpBridge\Discovery\Declarations;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Query\QuerySchema;

/**
 * Merges the layers that describe a route's inputs. Later layers win, and
 * every layer it touches leaves its mark in the field's origin trail, which is
 * what makes --explain and the divergence lint possible.
 *
 * Order: route parameters, then validation rules, then the API documentation
 * a package like Scramble or Scribe already declares, then the allowed query
 * of a list endpoint, then #[McpParam], then the fields the application fills
 * from the authenticated user.
 */
class SchemaBuilder
{
    public function __construct(
        protected RouteParamMapper $params,
        protected FormRequestReader $requests,
        protected RuleMapper $rules,
        protected AttributeReader $attributes,
        protected ReadsQueryDefinitions $queries,
        protected QuerySchema $querySchema,
        protected ReadsDocumentation $documentation,
        protected Repository $config,
    ) {}

    public function forRoute(Route $route, ToolCandidate $candidate): InputSchema
    {
        $target = $candidate->target;
        $method = $target instanceof RouteTarget ? $target->method : 'GET';
        $in = $this->wireIn($method);

        $declarations = $this->declarations($candidate);

        $schema = $this->params->map($route);

        foreach ($this->rulesFor($route, $declarations) as $path => $rules) {
            $schema = $schema->with($this->rules->map($path, $rules, $in));

            if ($this->isConfirmed($rules)) {
                $schema = $schema->with($this->confirmationTwin($path, $in));
            }
        }

        $documented = $this->documented($candidate);

        $schema = $schema
            ->merge($documented)
            ->merge($this->query($candidate));

        // The query layer wins on what the endpoint accepts, which is its
        // job, and loses on prose it generated rather than read. A developer
        // who wrote a sentence in #[QueryParameter] should not have it
        // replaced by "Keep only rows whose author contains this."
        $schema = $this->keepWrittenDescriptions($schema, $documented);

        $schema = $this->applyParams($schema, $declarations->params, $in);

        return $schema->sorted();
    }

    /**
     * Laravel's `confirmed` rule makes the request expect a second field, and
     * asking an agent to send the same value twice is asking it to reproduce a
     * form's own paperwork. So the twin is part of the schema, hidden, and the
     * executor copies the value across.
     *
     * @param  string|array<array-key, mixed>  $rules
     */
    protected function isConfirmed(string|array $rules): bool
    {
        $list = is_string($rules) ? explode('|', $rules) : $rules;

        foreach ($list as $rule) {
            if (is_string($rule) && ($rule === 'confirmed' || str_starts_with($rule, 'confirmed:'))) {
                return true;
            }
        }

        return false;
    }

    protected function confirmationTwin(string $path, WireIn $in): Field
    {
        $twin = $path.'_confirmation';

        return (new Field(
            path: $twin,
            type: FieldType::String,
            wire: new Wire(in: $in, name: $twin),
            origin: ['rules:confirmed'],
        ))->hidden('rules:confirmed', 'confirmation');
    }

    /**
     * Restore a description that came from a documentation attribute wherever
     * the query layer answered with a generated one.
     *
     * Only the generated ones yield. Two developers describing one field,
     * through a documentation attribute and through a query attribute, is a
     * real disagreement, and the later layer settles it as it settles
     * everything else.
     */
    protected function keepWrittenDescriptions(InputSchema $schema, InputSchema $documented): InputSchema
    {
        $fields = [];

        foreach ($schema->fields as $path => $field) {
            $written = $documented->get($path)?->description;

            $fields[$path] = $written !== null && in_array(QuerySchema::GENERATED, $field->origin, true)
                ? $field->with(description: $written)
                : $field;
        }

        return new InputSchema($fields);
    }

    /**
     * Parameters a documentation package already declares. They sit above
     * inference and below this package's own attributes, since the team wrote
     * them deliberately but for a different audience.
     */
    protected function documented(ToolCandidate $candidate): InputSchema
    {
        [$class, $action] = $this->action($candidate);

        if ($class === null) {
            return InputSchema::empty();
        }

        $documented = $this->documentation->read($class, $action);

        if ($documented === null) {
            return InputSchema::empty();
        }

        return $documented->input->hiding($documented->hidden, 'docs:ignored');
    }

    /**
     * The allowed filters, sorts, includes and fields of a list endpoint,
     * whether they were declared in attributes or read from a fluent Spatie
     * chain.
     */
    protected function query(ToolCandidate $candidate): InputSchema
    {
        [$class, $action] = $this->action($candidate);

        if ($class === null) {
            return InputSchema::empty();
        }

        $definition = $this->queries->read($class, $action);

        return $definition === null
            ? InputSchema::empty()
            : $this->querySchema->forDefinition($definition, $candidate->declared?->outputMaxItems);
    }

    /** @return array{0: class-string|null, 1: string|null} */
    protected function action(ToolCandidate $candidate): array
    {
        $class = $candidate->meta['controller'] ?? null;
        $action = $candidate->meta['action'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            return [null, null];
        }

        return [$class, is_string($action) ? $action : null];
    }

    /** @return array<string, string|array<int, mixed>> */
    protected function rulesFor(Route $route, Declarations $declarations): array
    {
        // #[McpInput] names the rules source for an action that validates
        // inline or type-hints a plain Request.
        return $declarations->input === null
            ? $this->requests->rules($route)
            : $this->requests->rulesFor($declarations->input->request);
    }

    protected function declarations(ToolCandidate $candidate): Declarations
    {
        [$class, $action] = $this->action($candidate);

        return $class === null
            ? Declarations::none()
            : $this->attributes->read($class, $action);
    }

    /**
     * #[McpParam] is the most specific layer: it describes, retypes, hides or
     * adds a field outright, and nothing after it overrides it.
     *
     * @param  list<McpParam>  $params
     */
    protected function applyParams(InputSchema $schema, array $params, WireIn $in): InputSchema
    {
        foreach ($params as $param) {
            // Adding a field needs the wire, which only a route knows here.
            // Shaping one the rules produced is the compiler's job, for
            // every source alike, so it is not done twice.
            if ($schema->get($param->name) !== null) {
                continue;
            }

            $schema = new InputSchema([
                ...$schema->fields,
                $param->name => $this->override($param, null, $in),
            ]);
        }

        return $schema;
    }

    protected function override(McpParam $param, ?Field $existing, WireIn $in): Field
    {
        $type = $param->type === null ? null : FieldType::tryFrom($param->type);

        $existing ??= new Field(
            path: $param->name,
            type: FieldType::String,
            wire: new Wire(in: $in, name: $param->name),
        );

        return $existing->with(
            type: $type,
            description: $param->description,
            enum: $param->enum === [] ? null : $param->enum,
            examples: $param->example === null ? null : [$param->example],
            hidden: $param->hidden || $existing->hidden,
            origin: [...$existing->origin, 'attribute:McpParam'],
        );
    }

    /** Reads and deletes carry inputs in the query string; writes carry a body. */
    protected function wireIn(string $method): WireIn
    {
        return in_array($method, ['GET', 'DELETE'], true) ? WireIn::Query : WireIn::Body;
    }
}
