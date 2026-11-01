<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Modules;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Contracts\LocatesModules;
use KayedSpace\McpBridge\Data\Module;
use ReflectionClass;
use Throwable;

/**
 * Which module each tool came from.
 *
 * Ownership is decided by file path, not by namespace or naming convention: a
 * class belongs to the module whose directory contains the file it was
 * declared in. That is true whatever the module package is called and whatever
 * namespace the team picked, and it needs no configuration to get right.
 */
class ModuleRegistry
{
    /** @var list<Module>|null */
    protected ?array $modules = null;

    /** @var array<class-string, string|null> */
    protected array $owners = [];

    /** @param array<string, LocatesModules> $locators */
    public function __construct(
        protected array $locators,
        protected Repository $config,
    ) {}

    /** @return list<Module> */
    public function modules(): array
    {
        return $this->modules ??= $this->locate();
    }

    public function enabled(): bool
    {
        return $this->config->boolean('mcp-bridge.modules.enabled', true) && $this->modules() !== [];
    }

    /**
     * The module a class belongs to, or null for application code that is not
     * in one.
     *
     * @param  class-string  $class
     */
    public function forClass(string $class): ?string
    {
        if (array_key_exists($class, $this->owners)) {
            return $this->owners[$class];
        }

        return $this->owners[$class] = $this->resolve($class);
    }

    /** @param class-string $class */
    protected function resolve(string $class): ?string
    {
        if (! $this->enabled() || ! class_exists($class)) {
            return null;
        }

        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        if ($file === false) {
            return null;
        }

        foreach ($this->modules() as $module) {
            if ($module->contains($file)) {
                return $module->name;
            }
        }

        return null;
    }

    /**
     * How a tool from a module is grouped, which is what a client with tool
     * search reads to narrow a large surface.
     *
     * @return list<string>
     */
    public function groups(?string $module): array
    {
        return match ($this->config->string('mcp-bridge.modules.grouping', 'module')) {
            'module' => $module === null ? [] : [$module],
            default => [],
        };
    }

    /**
     * A module's tools can be prefixed to keep two modules from both wanting
     * `list_invoices`. Off by default: a prefix every tool carries is noise
     * until it is needed, and the name collision is reported either way.
     */
    public function prefix(string $name, ?string $module): string
    {
        if ($module === null || ! $this->config->boolean('mcp-bridge.modules.always_prefix_module', false)) {
            return $name;
        }

        $prefix = str_replace('-', '_', strtolower($module));

        return str_starts_with($name, $prefix.'_') ? $name : "{$prefix}_{$name}";
    }

    /** The server a module's tools belong to, when each module gets its own. */
    public function server(?string $module, string $default): string
    {
        return $module !== null && $this->config->boolean('mcp-bridge.modules.per_module_servers', false)
            ? strtolower($module)
            : $default;
    }

    /** @return list<Module> */
    protected function locate(): array
    {
        if (! $this->config->boolean('mcp-bridge.modules.enabled', true)) {
            return [];
        }

        $wanted = array_values(array_filter(
            $this->config->array('mcp-bridge.modules.locators', ['*']),
            'is_string',
        ));

        $modules = [];

        foreach ($this->locators as $key => $locator) {
            if (! in_array('*', $wanted, true) && ! in_array($key, $wanted, true)) {
                continue;
            }

            foreach ($locator->modules() as $module) {
                if ($this->allowed($module)) {
                    $modules[$module->name] = $module;
                }
            }
        }

        ksort($modules);

        return array_values($modules);
    }

    /**
     * `only` is an allowlist: `*` is every module the locators found, and
     * an empty list is none. `except` removes from whatever `only` allowed.
     */
    protected function allowed(Module $module): bool
    {
        if (! $module->enabled) {
            return false;
        }

        $only = $this->names('only', ['*']);
        $except = $this->names('except');

        if (! in_array('*', $only, true) && ! in_array($module->name, $only, true)) {
            return false;
        }

        return ! in_array($module->name, $except, true);
    }

    /**
     * @param  list<string>  $default  the shipped value, for a key an older published file lacks
     * @return list<string>
     */
    protected function names(string $key, array $default = []): array
    {
        return array_values(array_filter(
            $this->config->array("mcp-bridge.modules.{$key}", $default),
            'is_string',
        ));
    }
}
