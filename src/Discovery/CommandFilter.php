<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Discovery;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;

/**
 * Which Artisan commands may become tools.
 *
 * Static analysis cannot tell what an arbitrary command touches, so this does
 * not pretend to know. It bounds the blast radius instead: a namespace
 * allowlist, which is reliable because enumerating safe namespaces is
 * tractable, and a deny list that holds even for a command someone opted in,
 * because enumerating dangerous commands is not.
 */
class CommandFilter
{
    /**
     * Commands this package will not expose whatever the configuration says,
     * unless each is named in `allow_denied`. Shipped as a floor under the
     * configurable deny list, so emptying that list in config cannot quietly
     * expose `db:wipe`.
     */
    public const ALWAYS_DENY = [
        'migrate', 'migrate:*', 'db:wipe', 'db:seed', 'down', 'up', 'tinker',
        'key:generate', 'config:clear', 'cache:clear', 'optimize:clear',
        'env:decrypt', 'env:encrypt', 'storage:link', 'vendor:publish',
        'schema:dump', 'queue:flush', 'queue:forget', 'queue:restart',
        'mcp-bridge:*',
    ];

    public function __construct(protected Repository $config) {}

    /**
     * A denied command is refused even when it carries #[McpTool], and even
     * when it would pass every other check.
     */
    public function isDenied(string $name): bool
    {
        if ($this->matches($name, $this->allowDenied())) {
            return false;
        }

        return $this->matches($name, [...self::ALWAYS_DENY, ...$this->denied()]);
    }

    /**
     * `*` lets any namespace opt in, which is the shipped default for a
     * package that is opt-in per command anyway, and an empty list lets none.
     * Naming the namespaces is the recommended production posture.
     */
    public function isInAllowedNamespace(string $name): bool
    {
        $namespaces = $this->namespaces();

        if (in_array('*', $namespaces, true)) {
            return true;
        }

        foreach ($namespaces as $namespace) {
            $prefix = rtrim($namespace, ':*');

            if ($name === $prefix || Str::startsWith($name, $prefix.':')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    protected function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    protected function denied(): array
    {
        return $this->strings('deny');
    }

    /** @return list<string> */
    protected function allowDenied(): array
    {
        return $this->strings('allow_denied');
    }

    /** @return list<string> */
    protected function namespaces(): array
    {
        return $this->strings('namespaces', ['*']);
    }

    /**
     * @param  list<string>  $default  the shipped value, for a key an older published file lacks
     * @return list<string>
     */
    protected function strings(string $key, array $default = []): array
    {
        return array_values(array_filter(
            $this->config->array("mcp-bridge.sources.command.{$key}", $default),
            'is_string',
        ));
    }
}
