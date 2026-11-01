<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/** A resource an application wrote by hand with laravel/mcp, served as it is. */
class HandbookResource extends Resource
{
    protected string $description = 'The staff handbook.';

    protected string $uri = 'file://handbook';

    public function handle(): Response
    {
        return Response::text('Be kind.');
    }
}
