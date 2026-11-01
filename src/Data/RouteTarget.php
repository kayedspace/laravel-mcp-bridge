<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * @phpstan-type RouteTargetArray array{type: 'route', method: string, uri: string, route_name: string|null}
 */
final readonly class RouteTarget implements Target
{
    public function __construct(
        public string $method,
        public string $uri,
        public ?string $routeName = null,
    ) {}

    public function type(): string
    {
        return 'route';
    }

    public function describe(): string
    {
        return "{$this->method} /{$this->uri}";
    }

    /** @return RouteTargetArray */
    public function toArray(): array
    {
        return [
            'type' => 'route',
            'method' => $this->method,
            'uri' => $this->uri,
            'route_name' => $this->routeName,
        ];
    }

    /** @param RouteTargetArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            method: $data['method'],
            uri: $data['uri'],
            routeName: $data['route_name'],
        );
    }
}
