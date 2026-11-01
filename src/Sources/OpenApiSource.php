<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\Declared;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\OpenApiTarget;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Discovery\ToolNamer;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Execution\OpenApiExecutor;
use KayedSpace\McpBridge\OpenApi\OpenApiDocuments;
use KayedSpace\McpBridge\Schema\JsonSchemaReader;
use KayedSpace\McpBridge\Support\Keys;

/**
 * The operations of an OpenAPI document as tools, for an API this
 * application reaches rather than serves.
 *
 * The document is already a declaration, so nothing is inferred: its
 * parameters and request body are the schema, its operationId is the name,
 * and its summary is the description. What this package adds is the same
 * thing it adds to a remote server: one endpoint and one token for the
 * agent, with the authority this application's configuration grants and
 * the API's own credential never leaving the server.
 */
class OpenApiSource implements Source
{
    /** The body media type an agent can send; anything else is not JSON. */
    protected const JSON = 'application/json';

    public function __construct(
        protected OpenApiDocuments $documents,
        protected ToolNamer $namer,
        protected JsonSchemaReader $schemas,
        protected OpenApiExecutor $executor,
        protected Repository $config,
    ) {}

    public function key(): string
    {
        return 'openapi';
    }

    public function discover(): iterable
    {
        $candidates = [];

        foreach ($this->documents->configured() as $key => $settings) {
            $document = $this->documents->load($key);

            if ($document === null) {
                continue;
            }

            foreach ($document->operations() as $operation) {
                $candidate = $this->candidate($key, $settings, $operation);

                if ($candidate !== null) {
                    $candidates[$candidate->id] = $candidate;
                }
            }
        }

        ksort($candidates);

        return array_values($candidates);
    }

    public function schema(ToolCandidate $candidate): InputSchema
    {
        $schema = InputSchema::empty();

        foreach ($this->parameters($candidate) as $parameter) {
            $in = match ($parameter['in'] ?? null) {
                'path' => WireIn::Path,
                'query' => WireIn::Query,
                default => null,
            };

            $name = $parameter['name'] ?? null;

            if ($in === null || ! is_string($name) || $name === '') {
                continue;
            }

            $property = is_array($parameter['schema'] ?? null) ? $parameter['schema'] : ['type' => 'string'];

            if (! isset($property['description']) && is_string($parameter['description'] ?? null)) {
                $property['description'] = $parameter['description'];
            }

            // A path parameter is always required: the URL has a hole in it.
            $required = $in === WireIn::Path || ($parameter['required'] ?? false) === true;

            $schema = $schema->merge($this->schemas->read([
                'properties' => [$name => $property],
                'required' => $required ? [$name] : [],
            ], $in));
        }

        $body = $candidate->meta['body'] ?? null;

        if (is_array($body)) {
            $schema = $schema->merge($this->schemas->read(Keys::strings($body), WireIn::Body));
        }

        return $schema->sorted();
    }

    public function validate(ToolCandidate $candidate): array
    {
        $unsupported = $candidate->meta['unsupported'] ?? [];

        return is_array($unsupported) && $unsupported !== []
            ? [Refusal::unbuildable($candidate->id, array_values(array_filter($unsupported, 'is_string')))]
            : [];
    }

    public function execute(ToolCall $call): SourceResult
    {
        return $this->executor->execute($call);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array{method: string, path: string, operation: array<string, mixed>}  $operation
     */
    protected function candidate(string $document, array $settings, array $operation): ?ToolCandidate
    {
        $details = $operation['operation'];
        $operationId = is_string($details['operationId'] ?? null) ? $details['operationId'] : null;
        $method = $operation['method'];
        $path = $operation['path'];

        if (! $this->allowed($settings, $operationId, "{$method} {$path}")) {
            return null;
        }

        [$body, $unsupported] = $this->body($details);

        return new ToolCandidate(
            source: $this->key(),
            id: "openapi:{$document}:{$method} {$path}",
            target: new OpenApiTarget($document, $method, $path),
            suggestedName: $this->name($document, $settings, $method, $path, $operationId),
            title: is_string($details['summary'] ?? null) ? $details['summary'] : null,
            description: $this->describe($document, $details, $method, $path),
            groups: [$document],
            safety: Safety::fromHttpMethod($method),
            declarations: [],
            meta: [
                'opted_in' => true,
                'ignored' => false,
                'document' => $document,
                'operation_id' => $operationId,
                'parameters' => array_values(array_filter(
                    is_array($details['parameters'] ?? null) ? $details['parameters'] : [],
                    'is_array',
                )),
                'body' => $body,
                'unsupported' => $unsupported,
            ],
            declared: new Declared(
                scopes: $this->strings($settings['scopes'] ?? null),
                abilities: $this->strings($settings['abilities'] ?? null),
            ),
        );
    }

    /**
     * The JSON body schema, or the reason the body cannot be sent: an agent
     * speaks JSON, so a form or a file upload is not a tool it can call.
     *
     * @param  array<string, mixed>  $details
     * @return array{0: array<array-key, mixed>|null, 1: list<string>}
     */
    protected function body(array $details): array
    {
        $requestBody = $details['requestBody'] ?? null;

        if (! is_array($requestBody)) {
            return [null, []];
        }

        $content = is_array($requestBody['content'] ?? null) ? $requestBody['content'] : [];
        $json = $content[self::JSON] ?? null;

        if (is_array($json)) {
            $schema = $json['schema'] ?? null;

            return [is_array($schema) ? $schema : null, []];
        }

        $offered = implode(', ', array_filter(array_keys($content), 'is_string'));

        return [null, ["the request body is not JSON (the document offers {$offered})"]];
    }

    /** @param array<string, mixed> $settings */
    protected function name(string $document, array $settings, string $method, string $path, ?string $operationId): string
    {
        $name = $this->namer->forOperation($method, $path, $operationId);
        $prefix = $settings['prefix'] ?? $document;

        // Prefixed by default, as a remote server's tools are: two documents
        // each with a listInvoices is the normal case, not the exception.
        return is_string($prefix) && $prefix !== ''
            ? Str::snake($prefix).'_'.$name
            : $name;
    }

    /** @param array<string, mixed> $details */
    protected function describe(string $document, array $details, string $method, string $path): string
    {
        $own = $details['description'] ?? $details['summary'] ?? null;
        $text = is_string($own) && $own !== '' ? $own : "{$method} {$path}";

        return rtrim(Str::limit($text, 1000, ''), '. ').". Calls the [{$document}] API.";
    }

    /**
     * `only` is an allowlist of operationIds or `METHOD /path` entries: `*`,
     * or leaving it out, exposes every operation, and an empty list exposes
     * none. `except` then removes from whatever `only` allowed.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function allowed(array $settings, ?string $operationId, string $signature): bool
    {
        $only = array_key_exists('only', $settings) ? $this->strings($settings['only']) : ['*'];
        $except = $this->strings($settings['except'] ?? null);
        $names = array_values(array_filter([$operationId, $signature]));

        if (! in_array('*', $only, true) && array_intersect($names, $only) === []) {
            return false;
        }

        return array_intersect($names, $except) === [];
    }

    /** @return list<array<string, mixed>> */
    protected function parameters(ToolCandidate $candidate): array
    {
        $parameters = $candidate->meta['parameters'] ?? [];

        /** @var list<array<string, mixed>> */
        return is_array($parameters) ? array_values(array_filter($parameters, 'is_array')) : [];
    }

    /** @return list<string> */
    protected function strings(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, 'is_string'))
            : [];
    }
}
