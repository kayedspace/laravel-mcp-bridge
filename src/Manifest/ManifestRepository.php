<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Manifest;

use ErrorException;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use KayedSpace\McpBridge\Data\ToolDefinition;

/**
 * Reads and writes the compiled tool manifest: one exported PHP array, so a
 * call costs a file read that OPcache has already warmed rather than a
 * discovery pass.
 *
 * The cache is an optimisation rather than the only way to have tools:
 * without a file, or with `discovery.cache`
 * off, the manifest is compiled from the application on first use and kept
 * for the life of the process. A server that listed nothing until someone
 * remembered `mcp-bridge:cache` was the first thing every new user hit.
 *
 * @phpstan-import-type ToolDefinitionArray from ToolDefinition
 */
class ManifestRepository
{
    /** Bumped whenever the stored shape changes, so an old file is ignored rather than misread. */
    public const VERSION = 2;

    /** @var array<string, ToolDefinition>|null */
    protected ?array $definitions = null;

    public function __construct(
        protected Application $app,
        protected Filesystem $files,
        protected Repository $config,
    ) {}

    public function path(): string
    {
        return $this->app->bootstrapPath('cache/mcp-bridge.php');
    }

    public function isCached(): bool
    {
        return $this->files->exists($this->path());
    }

    /**
     * Cached by a file this version can read. A file another version wrote
     * is treated as absent at run time, and an operator asking whether the
     * manifest is cached needs the same answer, or doctor says "ok" while
     * every process compiles on start.
     */
    public function isCurrent(): bool
    {
        return $this->isCached() && $this->isReadable(require $this->path());
    }

    /** @return array<string, ToolDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $stored = $this->usesCache() ? $this->stored() : null;

        return $this->definitions = $stored ?? $this->compiled();
    }

    public function find(string $name): ?ToolDefinition
    {
        return $this->all()[$name] ?? null;
    }

    /** @var array<string, array<string, ToolDefinition>> */
    protected array $byServer = [];

    /** @return array<string, ToolDefinition> */
    public function forServer(string $server): array
    {
        return $this->byServer[$server] ??= array_filter($this->all(), fn (ToolDefinition $tool): bool => $tool->server === $server);
    }

    /** @param iterable<ToolDefinition> $definitions */
    public function write(iterable $definitions): void
    {
        $tools = [];

        foreach ($definitions as $definition) {
            $tools[$definition->name] = $definition->toArray();
        }

        ksort($tools);

        $this->files->ensureDirectoryExists(dirname($this->path()));
        $this->files->put($this->path(), sprintf(
            '<?php return %s;'.PHP_EOL,
            var_export(['version' => self::VERSION, 'tools' => array_values($tools)], true),
        ));

        $this->definitions = null;
        $this->byServer = [];
    }

    public function clear(): void
    {
        $this->files->delete($this->path());

        $this->definitions = null;
        $this->byServer = [];
    }

    /**
     * `discovery.cache` off means the file is never read, even when one is
     * there: a developer who turned it off wants what the code says now, not
     * what it said when the file was last written.
     */
    protected function usesCache(): bool
    {
        return $this->config->boolean('mcp-bridge.discovery.cache', true) && $this->isCached();
    }

    /**
     * The file, or null when there is none worth reading.
     *
     * @return array<string, ToolDefinition>|null
     */
    protected function stored(): ?array
    {
        // A deploy clearing the file between the existence check and this
        // read leaves nothing to read, which is the same as no manifest.
        try {
            $stored = require $this->path();
        } catch (ErrorException) {
            return null;
        }

        if (! $this->isReadable($stored)) {
            return null;
        }

        $definitions = [];

        foreach ($stored['tools'] as $tool) {
            $definition = ToolDefinition::fromArray($tool);
            $definitions[$definition->name] = $definition;
        }

        return $definitions;
    }

    /**
     * Straight from the application, resolved here rather than injected so
     * that holding the repository never means holding every source.
     *
     * @return array<string, ToolDefinition>
     */
    protected function compiled(): array
    {
        $definitions = [];

        foreach ($this->app->make(ManifestCompiler::class)->compile()->definitions as $definition) {
            $definitions[$definition->name] = $definition;
        }

        return $definitions;
    }

    /**
     * A manifest written by another version of this package, or a truncated
     * one, is treated as absent so that it is rebuilt instead of misread.
     *
     * @phpstan-assert-if-true array{version: int, tools: list<ToolDefinitionArray>} $stored
     */
    protected function isReadable(mixed $stored): bool
    {
        if (! is_array($stored) || ($stored['version'] ?? null) !== self::VERSION) {
            return false;
        }

        if (! isset($stored['tools']) || ! is_array($stored['tools'])) {
            return false;
        }

        foreach ($stored['tools'] as $tool) {
            if (! is_array($tool) || ! isset($tool['name'], $tool['target'], $tool['input'], $tool['safety'])) {
                return false;
            }
        }

        return true;
    }
}
