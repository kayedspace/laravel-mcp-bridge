<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\OpenApi;

/**
 * One OpenAPI 3 document, read as plain arrays.
 *
 * The only interpretation done here is reference resolution: a `$ref` to
 * somewhere in the same document is replaced by what it points at, and an
 * `allOf` is folded into one object, so a source reads a schema the way its
 * author meant it and never meets a pointer. Anything else the document says
 * is handed on as written: it is already a declaration.
 */
final class OpenApiDocument
{
    protected const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** Deep enough for any real schema, shallow enough to stop a cycle. */
    protected const MAX_DEPTH = 32;

    /** @param array<string, mixed> $document */
    public function __construct(protected array $document) {}

    /** The first server the document names, which is where its paths live. */
    public function baseUrl(): ?string
    {
        $servers = $this->document['servers'] ?? null;
        $first = is_array($servers) ? ($servers[0] ?? null) : null;
        $url = is_array($first) ? ($first['url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Every operation, with the path item's shared parameters folded into
     * each and every reference resolved, sorted so a document always reads
     * the same way.
     *
     * @return list<array{method: string, path: string, operation: array<string, mixed>}>
     */
    public function operations(): array
    {
        $paths = $this->document['paths'] ?? null;

        if (! is_array($paths)) {
            return [];
        }

        $operations = [];

        foreach ($paths as $path => $item) {
            if (! is_string($path) || ! is_array($item)) {
                continue;
            }

            $item = $this->resolve($item);
            $shared = is_array($item['parameters'] ?? null) ? array_values($item['parameters']) : [];

            foreach (self::METHODS as $method) {
                $operation = $item[$method] ?? null;

                if (! is_array($operation)) {
                    continue;
                }

                $own = is_array($operation['parameters'] ?? null) ? array_values($operation['parameters']) : [];
                $operation['parameters'] = [...$shared, ...$own];

                /** @var array<string, mixed> $operation */
                $operations[strtoupper($method).' '.$path] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'operation' => $operation,
                ];
            }
        }

        ksort($operations);

        return array_values($operations);
    }

    /**
     * A node with every local reference replaced by what it points at.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    public function resolve(array $node, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $node;
        }

        $ref = $node['$ref'] ?? null;

        if (is_string($ref) && str_starts_with($ref, '#/')) {
            unset($node['$ref']);

            // What the pointer names, with the node's own keys on top, which
            // is how a reference is allowed to add a description of its own.
            $node = [...($this->pointer(substr($ref, 2)) ?? []), ...$node];
        }

        $parts = $node['allOf'] ?? null;

        if (is_array($parts)) {
            unset($node['allOf']);
            $node = $this->folded($node, $parts, $depth);
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->resolve($value, $depth + 1);
            }
        }

        return $node;
    }

    /**
     * `allOf` as the one object it describes: the properties and required
     * lists of every part, merged, which is what a reader of the schema is
     * expected to do and this package's own reader does not.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<array-key, mixed>  $parts
     * @return array<array-key, mixed>
     */
    protected function folded(array $node, array $parts, int $depth): array
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $required = is_array($node['required'] ?? null) ? $node['required'] : [];

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $part = $this->resolve($part, $depth + 1);

            $properties = [...$properties, ...(is_array($part['properties'] ?? null) ? $part['properties'] : [])];
            $required = [...$required, ...(is_array($part['required'] ?? null) ? $part['required'] : [])];
        }

        $node['type'] = 'object';
        $node['properties'] = $properties;

        $required = array_values(array_unique(array_filter($required, 'is_string')));

        if ($required !== []) {
            $node['required'] = $required;
        }

        return $node;
    }

    /** @return array<array-key, mixed>|null */
    protected function pointer(string $pointer): ?array
    {
        $node = $this->document;

        foreach (explode('/', $pointer) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return is_array($node) ? $node : null;
    }
}
