<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * A tool on another MCP server.
 *
 * @phpstan-type RemoteTargetArray array{type: 'remote', server: string, tool: string}
 */
final readonly class RemoteTarget implements Target
{
    public function __construct(
        public string $server,
        public string $tool,
    ) {}

    public function type(): string
    {
        return 'remote';
    }

    public function describe(): string
    {
        return "{$this->tool} on {$this->server}";
    }

    /** @return RemoteTargetArray */
    public function toArray(): array
    {
        return [
            'type' => 'remote',
            'server' => $this->server,
            'tool' => $this->tool,
        ];
    }

    /** @param RemoteTargetArray $data */
    public static function fromArray(array $data): self
    {
        return new self(server: $data['server'], tool: $data['tool']);
    }
}
