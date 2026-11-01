<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Commands;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Attributes\McpTool;

/** Opted in, but with no authority of its own to be given. */
#[McpTool(description: 'Opted in and nothing else.')]
class UnprotectedStats extends Command
{
    protected $signature = 'stats:unprotected';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
