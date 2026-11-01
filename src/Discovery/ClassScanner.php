<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Discovery;

use Illuminate\Contracts\Foundation\Application;
use KayedSpace\McpBridge\Support\Ast;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Finds the classes under a set of paths.
 *
 * Jobs, actions and models are not registered anywhere the way routes and
 * commands are, so they have to be looked for. The class name comes from the
 * file's own namespace rather than from its path, so an application with an
 * unusual PSR-4 layout is read correctly instead of being guessed at.
 */
class ClassScanner
{
    public function __construct(
        protected Application $app,
        protected Ast $ast,
    ) {}

    /**
     * @param  list<string>  $paths  absolute, or relative to the application root
     * @param  class-string|null  $extending  keep only subclasses of this
     * @return list<class-string>
     */
    public function in(array $paths, ?string $extending = null): array
    {
        $directories = [];

        foreach ($paths as $path) {
            $directories = [...$directories, ...$this->expand($path)];
        }

        $directories = array_values(array_unique($directories));

        if ($directories === []) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->name('*.php')->in($directories) as $file) {
            foreach ($this->ast->classesIn($this->path($file)) as $class) {
                if ($extending !== null && ! is_subclass_of($class, $extending)) {
                    continue;
                }

                $classes[$class] = $class;
            }
        }

        // Sorted so an unchanged application always compiles to an identical
        // manifest, whatever order the filesystem hands files back in.
        ksort($classes);

        return array_values($classes);
    }

    /**
     * One configured path as the directories it names. A glob is expanded,
     * which is how a modular application points at every module's Jobs
     * directory at once without listing the modules.
     *
     * @return list<string>
     */
    protected function expand(string $path): array
    {
        $resolved = is_dir($path) || str_contains($path, '*')
            ? $path
            : $this->app->basePath($path);

        if (! str_contains($resolved, '*')) {
            return is_dir($resolved) ? [$resolved] : [];
        }

        $absolute = str_starts_with($resolved, DIRECTORY_SEPARATOR)
            ? $resolved
            : $this->app->basePath($resolved);

        return glob($absolute, GLOB_ONLYDIR) ?: [];
    }

    protected function path(SplFileInfo $file): string
    {
        $real = $file->getRealPath();

        return $real === false ? $file->getPathname() : $real;
    }
}
