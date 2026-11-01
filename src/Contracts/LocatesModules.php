<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Contracts;

use KayedSpace\McpBridge\Data\Module;

/**
 * Where an application's modules are. Implemented once per module system, so
 * an in-house layout means adding a locator rather than touching discovery.
 */
interface LocatesModules
{
    /** @return list<Module> */
    public function modules(): array;
}
