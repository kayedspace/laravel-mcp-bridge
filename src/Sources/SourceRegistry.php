<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Contracts\Source;

/**
 * The sources this application compiles from. A third party adds one by
 * registering it here in a service provider; nothing else needs to change.
 */
class SourceRegistry
{
    /**
     * The order sources compile in, which is the order a name is claimed
     * in: a route first, because it carries authority the others only
     * declare, and a tool that mirrors it takes the prefix or the refusal.
     * A source registered by a third party compiles after these, by key.
     */
    public const PRECEDENCE = ['route', 'command', 'job', 'action', 'model', 'resource', 'remote', 'openapi'];

    /** @var array<string, Source> */
    protected array $sources = [];

    public function __construct(protected Repository $config) {}

    public function register(Source $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    public function get(string $key): ?Source
    {
        return $this->sources[$key] ?? null;
    }

    /** @return array<string, Source> */
    public function all(): array
    {
        $sources = $this->sources;

        uksort($sources, fn (string $a, string $b): int => [$this->rank($a), $a] <=> [$this->rank($b), $b]);

        return $sources;
    }

    protected function rank(string $key): int
    {
        $index = array_search($key, self::PRECEDENCE, true);

        return $index === false ? count(self::PRECEDENCE) : $index;
    }

    /** @return array<string, Source> */
    public function enabled(): array
    {
        return array_filter(
            $this->all(),
            fn (Source $source): bool => $this->config->boolean("mcp-bridge.sources.{$source->key()}.enabled", false),
        );
    }
}
