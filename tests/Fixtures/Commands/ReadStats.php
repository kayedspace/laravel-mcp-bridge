<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Commands;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpGroup;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** A command that only reads, and says so, so it is not treated as destructive. */
#[McpTool(description: 'Print the current statistics.')]
#[McpScope('ops:read')]
#[McpGroup('reporting')]
#[McpAbility('run-reports')]
#[IsReadOnly]
class ReadStats extends Command
{
    protected $signature = 'stats:show';

    public function handle(): int
    {
        $this->line('nothing changed');

        return self::SUCCESS;
    }
}
