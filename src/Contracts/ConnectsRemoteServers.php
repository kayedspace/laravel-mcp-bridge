<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Contracts;

/**
 * Talks to the MCP servers this application re-exports tools from.
 *
 * Raw payloads rather than a client's own objects, so the schema reader and
 * the executor stay the same whether the connection is laravel/mcp's client,
 * a stub in a test, or something else entirely.
 */
interface ConnectsRemoteServers
{
    /** @return list<string> the configured server keys */
    public function servers(): array;

    /**
     * The tools a server advertises, as `tools/list` returns them.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(string $server): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{content: list<array<string, mixed>>, isError: bool, structuredContent: array<string, mixed>|null}
     */
    public function call(string $server, string $tool, array $arguments): array;
}
