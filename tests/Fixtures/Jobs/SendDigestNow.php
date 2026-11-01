<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpQueue;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

/** Short enough that an agent is better off waiting for the answer. */
#[McpTool(description: 'Send the digest and wait.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
#[McpQueue(sync: true)]
class SendDigestNow implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    public function __construct(public string $audience) {}

    public function handle(): void
    {
        self::$ran = true;
    }
}
