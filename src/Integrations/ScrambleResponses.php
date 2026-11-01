<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Integrations;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\RouteTarget;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\OpenApi\OpenApiDocument;
use KayedSpace\McpBridge\Schema\JsonSchemaReader;
use Throwable;

/**
 * What a route answers, as dedoc/scramble infers it.
 *
 * A tool's output schema names the keys of a record and nothing more,
 * because reading an API Resource's `toArray()` to learn their types is a
 * type inference engine's job. Scramble is one, and an application that
 * publishes OpenAPI with it has already paid for that inference; this reads
 * the response it inferred and types the tool's `data` from it.
 *
 * Opt-in, because it runs Scramble's generator over every documented route
 * the first time a manifest compiles in a process, which is seconds on a
 * large application and a cost every test that compiles would pay.
 */
class ScrambleResponses
{
    /** @var array<string, mixed>|null|false false until the document has been generated once */
    protected array|null|false $document = false;

    public function __construct(
        protected Container $container,
        protected JsonSchemaReader $schemas,
        protected Repository $config,
    ) {}

    /** The record schema a route answers with, or null when nothing is certain. */
    public function read(ToolCandidate $candidate): ?InputSchema
    {
        $target = $candidate->target;

        if (! $target instanceof RouteTarget || ! $this->enabled()) {
            return null;
        }

        $operation = $this->operation($target);

        if ($operation === null) {
            return null;
        }

        $record = $this->record($operation);

        if ($record === null) {
            return null;
        }

        $schema = $this->schemas->read($record, WireIn::Body);

        return $schema->fields === [] ? null : $schema;
    }

    protected function enabled(): bool
    {
        return class_exists(Generator::class)
            && $this->config->boolean('mcp-bridge.integrations.scramble.enabled', true)
            && $this->config->boolean('mcp-bridge.integrations.scramble.responses', false);
    }

    /** @return array<array-key, mixed>|null */
    protected function operation(RouteTarget $target): ?array
    {
        $document = $this->document();

        if ($document === null) {
            return null;
        }

        $wanted = $this->path($target->uri);

        foreach ($document->operations() as $operation) {
            if ($operation['method'] === strtoupper($target->method) && $operation['path'] === $wanted) {
                // Scramble puts every resource in `components` and points at
                // it, so the record is reached through references.
                return $document->resolve($operation['operation']);
            }
        }

        return null;
    }

    /**
     * Scramble documents paths relative to its `api_path`, and always with a
     * leading slash, which is the shape the route's URI has to be put in.
     */
    protected function path(string $uri): string
    {
        $prefix = trim($this->config->string('scramble.api_path', 'api'), '/');
        $uri = trim($uri, '/');

        if ($prefix !== '' && ($uri === $prefix || str_starts_with($uri, $prefix.'/'))) {
            $uri = substr($uri, strlen($prefix));
        }

        return '/'.ltrim($uri, '/');
    }

    /**
     * The record a successful response carries: the first 2xx response's
     * JSON schema, with a `data` envelope unwrapped and a list reduced to its
     * member, because the shaper does the same to the response itself.
     *
     * @param  array<array-key, mixed>  $operation
     * @return array<string, mixed>|null
     */
    protected function record(array $operation): ?array
    {
        $responses = $operation['responses'] ?? null;

        if (! is_array($responses)) {
            return null;
        }

        foreach ($responses as $status => $response) {
            if (! is_array($response) || (int) $status < 200 || (int) $status > 299) {
                continue;
            }

            $content = $response['content'] ?? null;
            $json = is_array($content) ? ($content['application/json'] ?? null) : null;
            $schema = is_array($json) ? ($json['schema'] ?? null) : null;

            if (! is_array($schema)) {
                continue;
            }

            $properties = $schema['properties'] ?? null;
            $data = is_array($properties) ? ($properties['data'] ?? null) : null;

            if (is_array($data)) {
                $schema = $data;
            }

            if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
                $schema = $schema['items'];
            }

            if (! is_array($schema['properties'] ?? null)) {
                return null;
            }

            /** @var array<string, mixed> $schema */
            return $schema;
        }

        return null;
    }

    /**
     * The document Scramble generates, once per process. A generator that
     * throws, or a Scramble that is not configured, costs the output schema
     * and nothing else: the manifest still compiles.
     */
    protected function document(): ?OpenApiDocument
    {
        if ($this->document !== false) {
            return $this->document === null ? null : new OpenApiDocument($this->document);
        }

        try {
            $generator = $this->container->make(Generator::class);
            $generated = $generator(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
        } catch (Throwable) {
            $generated = null;
        }

        /** @var array<string, mixed>|null $generated */
        $this->document = is_array($generated) ? $generated : null;

        return $this->document === null ? null : new OpenApiDocument($this->document);
    }
}
