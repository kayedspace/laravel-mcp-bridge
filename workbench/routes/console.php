<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use KayedSpace\McpBridge\Models\McpToken;
use Workbench\App\Models\User;

Artisan::command('workbench:agent-token', function (): void {
    $user = User::query()->firstOrFail();

    $plain = McpToken::issue($user, 'Workbench', ['courses:read', 'courses:write'])->plainText;

    $this->line($plain);
})->purpose('Issue a fresh token for the workbench user');
