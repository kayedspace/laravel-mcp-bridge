<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Commands;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

/** Opted in, but it asks a question nobody will be there to answer. */
#[McpTool(description: 'Build a report, after asking which one.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class PromptingReport extends Command
{
    protected $signature = 'stats:prompt';

    public function handle(): int
    {
        $which = $this->ask('Which report?');

        $this->line("building {$which}");

        return self::SUCCESS;
    }
}
