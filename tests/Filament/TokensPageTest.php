<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Filament\Pages\McpTokens;
use KayedSpace\McpBridge\Models\McpToken;
use KayedSpace\McpBridge\Tests\Fixtures\AgentUser;
use Livewire\Livewire;

$installed = class_exists(Panel::class);

function tokenOwner(int $id): AgentUser
{
    return AgentUser::query()->create([
        'id' => $id,
        'name' => "Owner {$id}",
        'email' => "owner{$id}@example.com",
        'password' => 'x',
    ]);
}

beforeEach(function (): void {
    Schema::create('users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    (require __DIR__.'/../../database/migrations/2026_01_01_000000_create_mcp_bridge_tokens_table.php')->up();

    config()->set('auth.providers.users.model', AgentUser::class);
});

it('issues a token for the signed-in user and shows the plain text once', function (): void {
    $owner = tokenOwner(1);

    Livewire::actingAs($owner)
        ->test(McpTokens::class)
        ->callAction('issue', data: [
            'name' => 'Claude',
            'scopes' => ['courses:read'],
            'client' => 'claude-code',
            'expires_at' => Date::now()->addDays(30)->toDateTimeString(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $token = McpToken::query()->first();

    expect($token?->tokenable_id)->toBe(1)
        ->and($token?->scopes)->toBe(['courses:read'])
        ->and($token?->client)->toBe('claude-code')
        ->and($token?->expires_at)->not->toBeNull();
})->skip(! $installed, 'filament/filament is not installed');

it('lists only the signed-in user own tokens, and revokes one', function (): void {
    $owner = tokenOwner(1);
    $other = tokenOwner(2);

    $mine = $owner->createMcpToken('Claude', ['courses:read'])->accessToken;
    $theirs = $other->createMcpToken('n8n', ['*'])->accessToken;

    Livewire::actingAs($owner)
        ->test(McpTokens::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->callTableAction('revoke', $mine);

    // Revoked, not deleted: the audit trail still has something to name.
    expect($mine->fresh()?->isUsable())->toBeFalse()
        ->and($theirs->fresh()?->isUsable())->toBeTrue();
})->skip(! $installed, 'filament/filament is not installed');
