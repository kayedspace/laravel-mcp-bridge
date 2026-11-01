<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Models\McpToken;
use KayedSpace\McpBridge\Security\TokenGuard;
use KayedSpace\McpBridge\Security\TokenScopes;
use KayedSpace\McpBridge\Tests\Fixtures\AgentUser;
use KayedSpace\McpBridge\Tests\Fixtures\Member;
use KayedSpace\McpBridge\Tests\Fixtures\SanctumAgentUser;

function actor(int $id = 1): AgentUser
{
    return AgentUser::query()->create([
        'id' => $id,
        'name' => 'Agent Operator',
        'email' => "agent{$id}@example.com",
        'password' => 'x',
    ]);
}

/** Authenticates the way a request does: a bearer token and nothing else. */
function authenticate(string $token): ?Authenticatable
{
    request()->headers->set('Authorization', "Bearer {$token}");

    return app(TokenGuard::class, [
        'provider' => Auth::createUserProvider('users'),
        'request' => request(),
    ])->user();
}

beforeEach(function (): void {
    Schema::create('users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('mcp_bridge_tokens', function ($table): void {
        $table->id();
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->json('scopes');
        $table->string('client')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('revoked_at')->nullable();
        $table->timestamps();
    });

    config()->set('auth.providers.users.model', AgentUser::class);
});

describe('issuing', function (): void {
    it('returns the token once and stores only its hash', function (): void {
        $issued = actor()->createMcpToken('Claude', ['courses:read']);
        $token = $issued->accessToken;
        $plain = $issued->plainText;

        expect($plain)->toStartWith('mcpb_')
            ->and($token->token)->toBe(hash('sha256', $plain))
            ->and($token->token)->not->toContain($plain);
    });

    it('carries the scopes it was issued with', function (): void {
        $token = actor()->createMcpToken('Claude', ['courses:read', 'reports:*'])->accessToken;

        expect($token->can('courses:read'))->toBeTrue()
            ->and($token->can('reports:monthly'))->toBeTrue()
            ->and($token->can('courses:write'))->toBeFalse();
    });

    it('holds every scope when issued without naming any, spelled out as a wildcard', function (): void {
        $token = actor()->createMcpToken('Claude', ['*'])->accessToken;

        expect($token->scopes)->toBe(['*'])
            ->and($token->holdsEveryScope())->toBeTrue()
            ->and($token->can('anything'))->toBeTrue();
    });

    it('holds nothing when issued with an empty list', function (): void {
        // An empty list used to be the wildcard, so a token created without
        // a thought for scopes was a token that could do anything.
        $token = actor()->createMcpToken('Claude', [])->accessToken;

        expect($token->holdsEveryScope())->toBeFalse()
            ->and($token->can('anything'))->toBeFalse();
    });
});

describe('the guard', function (): void {
    it('resolves the owner the token was issued to, not the provider model with that id', function (): void {
        Schema::create('members', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('api_token')->nullable();
            $table->timestamps();
        });

        $member = Member::query()->create(['name' => 'M', 'email' => 'm@example.com', 'password' => 'x']);
        actor();

        $plain = McpToken::issue($member, 'Claude', ['*'])->plainText;

        expect(authenticate($plain))->toBeInstanceOf(Member::class);
    });

    it('resolves the user a token belongs to', function (): void {
        $user = actor(5);
        $plain = $user->createMcpToken('Claude', ['courses:read'])->plainText;

        $resolved = authenticate($plain);

        expect($resolved?->getAuthIdentifier())->toBe(5)
            ->and($resolved)->toBeInstanceOf(AgentUser::class);
    });

    it('puts the token on the user, so the scope check can ask', function (): void {
        $plain = actor()->createMcpToken('Claude', ['courses:read'])->plainText;

        $resolved = authenticate($plain);
        $scopes = app(TokenScopes::class);

        expect($resolved?->currentMcpToken())->not->toBeNull()
            ->and($scopes->holds($resolved, ['courses:read']))->toBeTrue()
            ->and($scopes->holds($resolved, ['courses:write']))->toBeFalse();
    });

    it('sits beside sanctum on one model, and answers the scope check from its own token', function (): void {
        config()->set('auth.providers.users.model', SanctumAgentUser::class);

        $user = SanctumAgentUser::query()->create([
            'name' => 'Both Traits',
            'email' => 'both@example.com',
            'password' => 'x',
        ]);

        $plain = $user->createMcpToken('Claude', ['courses:read'])->plainText;

        $resolved = authenticate($plain);

        // Sanctum's tokenCan() answers about a Sanctum access token, of which
        // there is none, so the package must not be asking it.
        expect($resolved)->toBeInstanceOf(SanctumAgentUser::class)
            ->and($resolved?->tokenCan('courses:read'))->toBeFalse()
            ->and(app(TokenScopes::class)->holds($resolved, ['courses:read']))->toBeTrue()
            ->and(app(TokenScopes::class)->holds($resolved, ['courses:write']))->toBeFalse();
    });

    it('refuses a revoked token immediately', function (): void {
        $issued = actor()->createMcpToken('Claude', ['*']);
        $token = $issued->accessToken;
        $plain = $issued->plainText;

        $token->revoke();

        expect(authenticate($plain))->toBeNull();
    });

    it('refuses an expired token', function (): void {
        $plain = actor()->createMcpToken('Claude', [], Date::now()->subMinute())->plainText;

        expect(authenticate($plain))->toBeNull();
    });

    it('refuses a token it never issued, and one that is simply wrong', function (): void {
        expect(authenticate('mcpb_'.str_repeat('a', 40)))->toBeNull()
            ->and(authenticate('some-other-packages-token'))->toBeNull();
    });

    it('records when a token was last used', function (): void {
        $issued = actor()->createMcpToken('Claude', ['*']);
        $token = $issued->accessToken;
        $plain = $issued->plainText;

        expect($token->last_used_at)->toBeNull();

        authenticate($plain);

        expect($token->fresh()?->last_used_at)->not->toBeNull();
    });

    it('answers a user with no token with no scopes at all', function (): void {
        // A user read from a session or a factory carries no credential, and
        // answering yes would turn a token check into no check.
        $refusal = app(TokenScopes::class)->refusalFor(['courses:read'], actor());

        expect($refusal)->toContain('holds no token');
    });
});

describe('the commands', function (): void {
    it('issues a token for a user by email', function (): void {
        actor()->forceFill(['email' => 'ops@example.com'])->save();

        $this->artisan('mcp-bridge:token ops@example.com --name=Claude --scopes=courses:read,reports:*')
            ->expectsOutputToContain('mcpb_')
            ->expectsOutputToContain('courses:read, reports:*')
            ->assertSuccessful();

        expect(McpToken::query()->count())->toBe(1);
    });

    it('warns when the token it issues holds every scope', function (): void {
        actor()->forceFill(['email' => 'ops@example.com'])->save();

        $this->artisan('mcp-bridge:token ops@example.com')
            ->expectsOutputToContain('every scope (*)')
            ->expectsOutputToContain('Pass --scopes')
            ->assertSuccessful();

        expect(McpToken::query()->first()?->scopes)->toBe(['*']);
    });

    it('reads an empty scope list as a token that holds nothing', function (): void {
        actor()->forceFill(['email' => 'ops@example.com'])->save();

        $this->artisan('mcp-bridge:token ops@example.com --scopes=')
            ->expectsOutputToContain('mcpb_')
            ->assertSuccessful();

        expect(McpToken::query()->first()?->scopes)->toBe([]);
    });

    it('says so when no user matches', function (): void {
        $this->artisan('mcp-bridge:token nobody@example.com')
            ->expectsOutputToContain('No user matched')
            ->assertFailed();
    });

    it('lists and revokes', function (): void {
        $token = actor()->createMcpToken('Claude', ['courses:read'])->accessToken;

        $this->artisan('mcp-bridge:tokens')->expectsOutputToContain('Claude')->assertSuccessful();

        $this->artisan("mcp-bridge:tokens --revoke={$token->id}")
            ->expectsOutputToContain('is revoked')
            ->assertSuccessful();

        expect($token->fresh()?->isUsable())->toBeFalse();
    });

    it('prunes what stopped working long ago, and keeps what just did', function (): void {
        $old = actor()->createMcpToken('Old', ['*'])->accessToken;
        $old->forceFill(['revoked_at' => Date::now()->subDays(60)])->save();

        $recent = actor(2)->createMcpToken('Recent', ['*'])->accessToken;
        $recent->revoke();

        $this->artisan('mcp-bridge:prune-tokens --days=30')->assertSuccessful();

        expect(McpToken::query()->count())->toBe(1)
            ->and(McpToken::query()->first()?->name)->toBe('Recent');
    });
});
