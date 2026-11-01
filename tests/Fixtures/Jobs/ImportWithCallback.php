<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Jobs;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

/** Opted in, but nothing an agent sends could build it. */
#[McpTool]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class ImportWithCallback implements ShouldQueue
{
    use Dispatchable;

    public function __construct(
        public Closure $onEach,
        public $rows,
    ) {}

    public function handle(): void
    {
        //
    }
}
