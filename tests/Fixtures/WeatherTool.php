<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** A tool an application wrote by hand with laravel/mcp, served as it is. */
class WeatherTool extends Tool
{
    protected string $description = 'Tell the weather.';

    public function handle(Request $request): Response
    {
        return Response::text('Sunny.');
    }
}
