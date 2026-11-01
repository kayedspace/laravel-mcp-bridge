<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Server;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Which configured server the current connection is talking to.
 *
 * Resolved from the request path over HTTP, because one generated class
 * serves every configured endpoint, and from the handle `mcp:start` was
 * given over stdio, where there is no request to read a path from.
 */
class ServerKeyResolver
{
    public function __construct(
        protected Repository $config,
        protected Application $app,
    ) {}

    /**
     * The server a stdio process is serving, set when `mcp:start <handle>`
     * registers one. There is no request to read the path from, and the
     * handle is the only thing that says which server was asked for.
     */
    protected static ?string $local = null;

    public static function serving(?string $key): void
    {
        self::$local = $key;
    }

    /**
     * Whether this process is a stdio server rather than an HTTP one.
     *
     * The difference that matters elsewhere is lifetime: a stdio process
     * lives for the whole client session, so there is no response to defer
     * work past and nothing to flush a queue of it.
     */
    public static function servingLocally(): bool
    {
        return self::$local !== null;
    }

    public function current(): string
    {
        if (self::$local !== null) {
            return self::$local;
        }

        if (! $this->app->bound('request')) {
            return 'default';
        }

        return $this->forPath($this->app->make('request')->path());
    }

    public function forPath(string $path): string
    {
        $path = '/'.trim($path, '/');

        foreach ($this->config->array('mcp-bridge.servers', []) as $key => $server) {
            $configured = is_array($server) ? ($server['path'] ?? null) : null;

            if (is_string($configured) && '/'.trim($configured, '/') === $path) {
                return (string) $key;
            }
        }

        return 'default';
    }
}
