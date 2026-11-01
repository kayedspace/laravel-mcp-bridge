<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Commands;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'A report that cannot be built.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class FailingReport extends Command
{
    protected $signature = 'stats:fail';

    public function handle(): int
    {
        $this->line('starting');
        $this->error('the warehouse is unreachable');

        return self::FAILURE;
    }
}
