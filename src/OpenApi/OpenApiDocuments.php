<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\OpenApi;

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as Http;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The OpenAPI documents the configuration names, loaded once per process.
 *
 * A document is a file or a URL, in JSON or, when `symfony/yaml` is
 * installed, YAML. One that cannot be read yields no tools rather than
 * failing the build, as a remote server that cannot be reached does: the
 * rest of the surface is still correct, and `mcp-bridge:doctor` is where a
 * reading problem belongs.
 */
class OpenApiDocuments
{
    /** @var array<string, OpenApiDocument|null> */
    protected array $loaded = [];

    public function __construct(
        protected Http $http,
        protected Filesystem $files,
        protected Repository $config,
    ) {}

    /**
     * The configured documents that name a spec, keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function configured(): array
    {
        $documents = [];

        foreach ($this->config->array('mcp-bridge.sources.openapi.documents', []) as $key => $settings) {
            if (is_string($key) && is_array($settings) && is_string($settings['spec'] ?? null)) {
                /** @var array<string, mixed> $settings */
                $documents[$key] = $settings;
            }
        }

        return $documents;
    }

    /** @return array<string, mixed> */
    public function settings(string $key): array
    {
        return $this->configured()[$key] ?? [];
    }

    public function load(string $key): ?OpenApiDocument
    {
        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        $spec = $this->settings($key)['spec'] ?? null;

        if (! is_string($spec) || $spec === '') {
            return $this->loaded[$key] = null;
        }

        try {
            $parsed = $this->parse($this->contents($spec));
        } catch (Throwable) {
            $parsed = null;
        }

        return $this->loaded[$key] = $parsed === null ? null : new OpenApiDocument($parsed);
    }

    protected function contents(string $spec): string
    {
        if (str_starts_with($spec, 'http://') || str_starts_with($spec, 'https://')) {
            return $this->http->get($spec)->throw()->body();
        }

        return $this->files->get($spec);
    }

    /** @return array<string, mixed>|null */
    protected function parse(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        if (! class_exists(Yaml::class)) {
            return null;
        }

        $yaml = Yaml::parse($raw);

        /** @var array<string, mixed>|null */
        return is_array($yaml) ? $yaml : null;
    }
}
