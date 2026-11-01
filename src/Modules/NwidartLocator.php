<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Modules;

use Illuminate\Contracts\Container\Container;
use KayedSpace\McpBridge\Contracts\LocatesModules;
use KayedSpace\McpBridge\Data\Module;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module as NwidartModule;
use Throwable;

/**
 * Modules as `nwidart/laravel-modules` knows them.
 *
 * Its own repository is asked rather than its directory structure read,
 * because a module that is disabled there must stay invisible here: the
 * application has already said it is off.
 */
class NwidartLocator implements LocatesModules
{
    public function __construct(protected Container $container) {}

    /** @return list<Module> */
    public function modules(): array
    {
        if (! interface_exists(RepositoryInterface::class) || ! $this->container->bound(RepositoryInterface::class)) {
            return [];
        }

        try {
            $repository = $this->container->make(RepositoryInterface::class);
        } catch (Throwable) {
            // An application that has the package installed but not booted.
            return [];
        }

        $modules = [];

        /** @var iterable<NwidartModule> $all */
        $all = $repository->all();

        foreach ($all as $module) {
            $modules[] = new Module(
                name: $module->getName(),
                path: $module->getPath(),
                enabled: $module->isEnabled(),
            );
        }

        return $modules;
    }
}
