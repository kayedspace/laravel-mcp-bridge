<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Models\McpToken;
use KayedSpace\McpBridge\Tests\Fixtures\Member;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/2026_01_01_000000_create_mcp_bridge_tokens_table.php')->up();

    Schema::create('members', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->string('api_token')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    $this->member = Member::query()->create(['name' => 'Mia', 'email' => 'mia@example.com', 'password' => 'x']);
    $this->other = Member::query()->create(['name' => 'Omar', 'email' => 'omar@example.com', 'password' => 'x']);
});

function locally(): void
{
    app()['env'] = 'local';
}

/**
 * Opened by the gate rather than the environment, because an environment
 * other than `testing` turns CSRF verification back on and the form posts
 * below would answer 419 before reaching the controller.
 */
function opened(): void
{
    Gate::define('viewMcpBridge', fn (): bool => true);
}

it('is a 404 when switched off', function (): void {
    locally();
    config(['mcp-bridge.panel.enabled' => false]);

    $this->actingAs($this->member)->get('/mcp-bridge')->assertNotFound();
});

it('opens to a signed-in user in the local environment and to nobody else', function (): void {
    locally();

    $this->get('/mcp-bridge')->assertForbidden();
    $this->actingAs($this->member)->get('/mcp-bridge')->assertOk()->assertSee('No tokens yet');
});

it('fails closed outside local until the gate is defined, and then the gate decides everywhere', function (): void {
    $this->actingAs($this->member)->get('/mcp-bridge')->assertForbidden();

    Gate::define('viewMcpBridge', fn (Member $user): bool => $user->email === 'mia@example.com');

    $this->actingAs($this->member)->get('/mcp-bridge')->assertOk();
    $this->actingAs($this->other)->get('/mcp-bridge')->assertForbidden();

    locally();
    $this->actingAs($this->other)->get('/mcp-bridge')->assertForbidden();
});

it('lists only the signed-in user\'s tokens and offers the scopes tools declare', function (): void {
    opened();
    McpToken::issue($this->member, 'Mine', ['courses:read']);
    McpToken::issue($this->other, 'Theirs', ['*']);

    $this->actingAs($this->member)->get('/mcp-bridge')
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs')
        ->assertSee('courses:read');
});

it('issues a token from ticked and typed scopes and shows the plain text once', function (): void {
    opened();

    $response = $this->actingAs($this->member)->post('/mcp-bridge/tokens', [
        'name' => 'Claude',
        'scopes' => ['courses:read'],
        'other_scopes' => 'reports:read, courses:read',
        'client' => 'claude-code',
        'expires_in_days' => 30,
    ]);

    $token = McpToken::query()->whereMorphedTo('tokenable', $this->member)->sole();

    expect($token->name)->toBe('Claude')
        ->and($token->scopes)->toBe(['courses:read', 'reports:read'])
        ->and($token->client)->toBe('claude-code')
        ->and($token->expires_at?->isSameDay(now()->addDays(30)))->toBeTrue();

    $response->assertRedirect('/mcp-bridge');
    $this->followRedirects($response)->assertSee('shown once')->assertSee('mcpb_');
    $this->get('/mcp-bridge')->assertDontSee('mcpb_');
});

it('collapses a ticked star to every scope, and nothing ticked to nothing', function (): void {
    opened();

    $this->actingAs($this->member)->post('/mcp-bridge/tokens', ['name' => 'All', 'scopes' => ['*', 'courses:read']]);
    $this->actingAs($this->member)->post('/mcp-bridge/tokens', ['name' => 'None', 'expires_in_days' => '']);

    expect(McpToken::query()->where('name', 'All')->sole()->scopes)->toBe(['*'])
        ->and(McpToken::query()->where('name', 'None')->sole()->scopes)->toBe([])
        ->and(McpToken::query()->where('name', 'None')->sole()->expires_at)->toBeNull();
});

it('validates the form and keeps the input', function (): void {
    opened();

    $this->actingAs($this->member)->from('/mcp-bridge')
        ->post('/mcp-bridge/tokens', ['name' => '', 'expires_in_days' => 0])
        ->assertRedirect('/mcp-bridge')
        ->assertSessionHasErrors(['name', 'expires_in_days']);

    expect(McpToken::query()->count())->toBe(0);
});

it('revokes the user\'s own token and does not find another user\'s', function (): void {
    opened();
    $mine = McpToken::issue($this->member, 'Mine', ['*'])->accessToken;
    $theirs = McpToken::issue($this->other, 'Theirs', ['*'])->accessToken;

    $this->actingAs($this->member)->delete("/mcp-bridge/tokens/{$mine->getKey()}")->assertRedirect('/mcp-bridge');
    $this->actingAs($this->member)->delete("/mcp-bridge/tokens/{$theirs->getKey()}")->assertNotFound();

    expect($mine->fresh()?->revoked_at)->not->toBeNull()
        ->and($theirs->fresh()?->revoked_at)->toBeNull();
});

it('refuses to issue a token for a user model without the trait, the way the CLI does', function (): void {
    opened();

    $this->actingAs(new TestUser(5))->from('/mcp-bridge')
        ->post('/mcp-bridge/tokens', ['name' => 'Claude', 'scopes' => ['*']])
        ->assertRedirect('/mcp-bridge')
        ->assertSessionHasErrors(['name']);

    $this->actingAs(new TestUser(5))->get('/mcp-bridge')->assertOk()->assertSee('cannot hold a token yet');

    expect(McpToken::query()->count())->toBe(0);
});

it('pages through many tokens, and counts only the usable ones as active', function (): void {
    opened();

    foreach (range(1, 12) as $n) {
        McpToken::issue($this->member, "Token {$n}", ['courses:read']);
    }

    McpToken::issue($this->member, 'Old', ['*'])->accessToken->revoke();

    $this->actingAs($this->member)->get('/mcp-bridge')
        ->assertOk()
        ->assertSee('Page 1 of 2, 13 in all')
        ->assertSee('<strong>12</strong><span>active tokens</span>', false);

    $this->actingAs($this->member)->get('/mcp-bridge?page=2')->assertOk()->assertSee('Page 2 of 2');
});

describe('usage and activity', function (): void {
    beforeEach(function (): void {
        createAuditTable();
        opened();

        $this->mine = McpToken::issue($this->member, 'Mine', ['courses:read'])->accessToken;
        $this->theirs = McpToken::issue($this->other, 'Theirs', ['*'])->accessToken;

        $row = fn (Member $who, McpToken $token, string $tool, string $outcome, array $extra = []): McpAuditEntry => McpAuditEntry::query()->create([
            'tool' => $tool, 'source' => 'route', 'server' => 'default',
            'actor_type' => $who::class, 'actor_id' => (string) $who->getKey(),
            'token_type' => $token->getMorphClass(), 'token_id' => (string) $token->getKey(),
            'client' => 'claude-code', 'request_id' => uniqid(), 'arguments' => ['per_page' => 2],
            'outcome' => $outcome, 'error_code' => $outcome === 'refused' ? 'MCP_BRIDGE_SCOPE' : null,
            'status' => $outcome === 'allowed' ? 200 : null, 'duration_ms' => 12, 'confirmed' => false,
            'created_at' => now(), ...$extra,
        ]);

        $row($this->member, $this->mine, 'list_courses', 'allowed');
        $row($this->member, $this->mine, 'create_course', 'refused');
        $row($this->member, $this->mine, 'get_course', 'allowed', ['created_at' => now()->subDays(10)]);
        $row($this->other, $this->theirs, 'purge_courses', 'allowed');

        // Made with a Sanctum token as this user, so it is theirs whatever
        // the credential, and it names the credential it came with.
        $row($this->member, $this->mine, 'search_courses', 'allowed', ['token_type' => 'Laravel\\Sanctum\\PersonalAccessToken', 'token_id' => '900']);

        // Another user's Sanctum token that happens to share this user's
        // package token id: an equal id from another table is not theirs.
        $row($this->other, $this->theirs, 'export_courses', 'allowed', ['token_type' => 'Laravel\\Sanctum\\PersonalAccessToken', 'token_id' => (string) $this->mine->getKey()]);
    });

    it('counts this week\'s calls per token on the tokens page', function (): void {
        $this->actingAs($this->member)->get('/mcp-bridge')
            ->assertOk()
            ->assertSee('Issue a token')
            ->assertSeeInOrder(['<strong>1</strong><span>active tokens</span>', '<strong>3</strong><span>calls in the last 7 days</span>', '<strong>1</strong><span>refused in the last 7 days</span>'], false)
            ->assertSee('calls in 7 days');
    });

    it('lists only the calls made as this user, newest first, and filters by outcome', function (): void {
        $this->actingAs($this->member)->get('/mcp-bridge/activity')
            ->assertOk()
            ->assertSee('list_courses')
            ->assertSee('create_course')
            ->assertSee('MCP_BRIDGE_SCOPE')
            ->assertSee('search_courses')
            ->assertSee('Personal Access Token')
            ->assertSee('Mine')
            ->assertDontSee('purge_courses')
            ->assertDontSee('export_courses');

        $this->actingAs($this->member)->get('/mcp-bridge/activity?outcome=refused')
            ->assertOk()
            ->assertSee('create_course')
            ->assertDontSee('list_courses');

        // A token filter naming someone else's token is ignored rather than honoured.
        $this->actingAs($this->member)->get('/mcp-bridge/activity?token='.$this->theirs->getKey())
            ->assertOk()
            ->assertSee('list_courses')
            ->assertDontSee('purge_courses');
    });
});
