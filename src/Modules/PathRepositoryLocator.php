<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Modules;

use Illuminate\Contracts\Foundation\Application;
use KayedSpace\McpBridge\Contracts\LocatesModules;
use KayedSpace\McpBridge\Data\Module;

/**
 * Modules as Composer path repositories, which is how a lot of modular
 * applications are laid out without a module package at all: a `packages/*`
 * or `modules/*` directory wired in through composer.json.
 *
 * Read from composer.json rather than from a convention, so the directories
 * this finds are exactly the ones the application actually loads.
 */
class PathRepositoryLocator implements LocatesModules
{
    public function __construct(protected Application $app) {}

    /** @return list<Module> */
    public function modules(): array
    {
        $modules = [];

        foreach ($this->urls() as $url) {
            // A path repository url is usually a glob: packages/*.
            foreach (glob($this->app->basePath($url), GLOB_ONLYDIR) ?: [] as $path) {
                $modules[] = new Module(name: basename($path), path: $path);
            }
        }

        return $modules;
    }

    /** @return list<string> */
    protected function urls(): array
    {
        $composer = $this->app->basePath('composer.json');

        if (! is_readable($composer)) {
            return [];
        }

        $contents = file_get_contents($composer);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        $repositories = is_array($decoded) ? ($decoded['repositories'] ?? []) : [];

        if (! is_array($repositories)) {
            return [];
        }

        $urls = [];

        foreach ($repositories as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = $repository['url'] ?? null;

            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
