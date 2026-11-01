<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Modules;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use KayedSpace\McpBridge\Contracts\LocatesModules;
use KayedSpace\McpBridge\Data\Module;

/**
 * Modules as directories, for an application whose modules are a folder
 * convention and its own service provider rather than a package.
 *
 * This is the common case and the one the other locators miss. A team with
 * `app/Modules/Billing` has routes in the router, commands in the console
 * registry and classes under a path the scanners already accept, so nothing
 * about discovery needs a module package. The only thing missing is the
 * module's *name*, for grouping, prefixing and per-module servers, and a
 * directory name is that answer.
 *
 * Configured as globs: `['app/Modules/*']` makes every directory under it a
 * module named after itself.
 */
class DirectoryLocator implements LocatesModules
{
    public function __construct(
        protected Repository $config,
        protected Application $app,
    ) {}

    /** @return list<Module> */
    public function modules(): array
    {
        $modules = [];

        foreach ($this->patterns() as $pattern) {
            $absolute = str_starts_with($pattern, DIRECTORY_SEPARATOR)
                ? $pattern
                : $this->app->basePath($pattern);

            foreach (glob($absolute, GLOB_ONLYDIR) ?: [] as $directory) {
                $modules[] = new Module(name: basename($directory), path: $directory);
            }
        }

        return $modules;
    }

    /** @return list<string> */
    protected function patterns(): array
    {
        return array_values(array_filter(
            $this->config->array('mcp-bridge.modules.directories', []),
            'is_string',
        ));
    }
}
