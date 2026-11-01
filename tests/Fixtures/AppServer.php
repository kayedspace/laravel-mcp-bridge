<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Laravel\Mcp\Server;

/** An application's own laravel/mcp server, registered at a path the package also uses. */
class AppServer extends Server
{
    protected array $tools = [WeatherTool::class];
}
