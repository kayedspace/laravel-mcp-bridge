<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * An operation in an OpenAPI document: a method and a path on an API this
 * application reaches over HTTP rather than serves.
 *
 * @phpstan-type OpenApiTargetArray array{type: 'openapi', document: string, method: string, path: string}
 */
final readonly class OpenApiTarget implements Target
{
    public function __construct(
        public string $document,
        public string $method,
        public string $path,
    ) {}

    public function type(): string
    {
        return 'openapi';
    }

    public function describe(): string
    {
        return "{$this->method} {$this->path} on the {$this->document} API";
    }

    /** @return OpenApiTargetArray */
    public function toArray(): array
    {
        return [
            'type' => 'openapi',
            'document' => $this->document,
            'method' => $this->method,
            'path' => $this->path,
        ];
    }

    /** @param OpenApiTargetArray $data */
    public static function fromArray(array $data): self
    {
        return new self(document: $data['document'], method: $data['method'], path: $data['path']);
    }
}
