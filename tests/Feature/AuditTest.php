<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Audit\AuditRecorder;
use KayedSpace\McpBridge\Audit\DatabaseAuditLogger;
use KayedSpace\McpBridge\Audit\LogAuditLogger;
use KayedSpace\McpBridge\Audit\NullAuditLogger;
use KayedSpace\McpBridge\Contracts\StoresAudit;
use KayedSpace\McpBridge\Data\AuditEntry;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Enums\Outcome;
use KayedSpace\McpBridge\Events\ToolCalled;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Models\McpToken;
use KayedSpace\McpBridge\Server\ServerKeyResolver;
use KayedSpace\McpBridge\Tests\Fixtures\AgentUser;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;

/**
 * The audit is written by the tool itself, so these calls go over the real
 * protocol: a test that reimplemented the handler would pass even if the
 * handler stopped recording.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callOverProtocol(string $tool, array $arguments = []): array
{
    test()->actingAs(new TestUser, 'mcp-bridge');

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $tool,
            'arguments' => $arguments,
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
                'io.modelcontextprotocol/clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
            ],
        ],
    ], [
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'tools/call',
        'Mcp-Name' => $tool,
    ]);

    $response->assertOk();

    return $response->json();
}

beforeEach(function (): void {
    createAuditTable();

    defineApiRoutes();

    app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);
});

it('writes a line for a call that ran', function (): void {
    callOverProtocol('list_courses', ['per_page' => 2]);

    $entry = McpAuditEntry::query()->first();

    expect($entry?->tool)->toBe('list_courses')
        ->and($entry?->outcome)->toBe(Outcome::Allowed->value)
        ->and($entry?->arguments)->toBe(['per_page' => 2])
        ->and($entry?->getAttribute('source'))->toBe('route')
        ->and($entry?->getAttribute('actor_id'))->toBe('1')
        ->and($entry?->getAttribute('request_id'))->not->toBeEmpty();
});

it('records the client the connection declared', function (): void {
    callOverProtocol('list_courses', ['per_page' => 1]);

    // From the clientInfo the stateless protocol carries on every request;
    // nothing else names the client, and the column was always empty.
    expect(McpAuditEntry::query()->first()?->getAttribute('client'))->toBe('pest');
});

it('records which token made the call', function (): void {
    $actor = (new AgentUser)->forceFill(['id' => 1]);
    $actor->exists = true;
    $actor->withMcpToken((new McpToken)->forceFill(['id' => 7, 'scopes' => ['*']]));

    $call = callTool(toolNamed('list_courses'), actor: $actor);

    app(AuditRecorder::class)->record($call, ToolResult::ok('ok'), 1, Outcome::Allowed, 200);
    app(DeferredCallbackCollection::class)->invoke();

    // One person's Claude and n8n tokens look alike by actor, and revoking
    // the right one starts with knowing which one did what.
    expect(McpAuditEntry::query()->first())
        ->token_id->toBe('7')
        ->token_type->toBe((new McpToken)->getMorphClass());
});

it('falls through to a Sanctum token when the call came without a package token', function (): void {
    $sanctum = new class extends Model
    {
        protected $guarded = [];
    };
    $sanctum->forceFill(['id' => 5]);

    $actor = new class extends AgentUser
    {
        public ?Model $sanctumToken = null;

        public function currentAccessToken(): ?Model
        {
            return $this->sanctumToken;
        }
    };
    $actor->forceFill(['id' => 1]);
    $actor->exists = true;
    $actor->sanctumToken = $sanctum;

    app(AuditRecorder::class)->record(callTool(toolNamed('list_courses'), actor: $actor), ToolResult::ok('ok'), 1, Outcome::Allowed, 200);
    app(DeferredCallbackCollection::class)->invoke();

    expect(McpAuditEntry::query()->first())
        ->token_id->toBe('5')
        ->token_type->toBe($sanctum->getMorphClass());
});

it('writes a line for a call that was refused', function (): void {
    // purge_course is destructive, so the first call is a confirmation
    // handshake rather than the work itself.
    callOverProtocol('purge_course');

    $entry = McpAuditEntry::query()->first();

    expect($entry?->outcome)->toBe(Outcome::Refused->value)
        ->and($entry?->getAttribute('error_code'))->toBe('MCP_BRIDGE_CONFIRMATION_REQUIRED');
});

it('records a failed call as failed', function (): void {
    callOverProtocol('create_course', ['status' => 'draft']);

    expect(McpAuditEntry::query()->first()?->outcome)->toBe(Outcome::Failed->value);
});

it('never writes a secret down', function (): void {
    config()->set('mcp-bridge.security.redact', ['password', 'token']);

    callOverProtocol('create_course', [
        'title' => 'Neurology',
        'status' => 'draft',
        'password' => 'hunter2',
    ]);

    $arguments = McpAuditEntry::query()->first()?->arguments ?? [];

    expect($arguments['password'])->toBe('[redacted]')
        ->and($arguments['title'])->toBe('Neurology')
        ->and(json_encode($arguments))->not->toContain('hunter2');
});

it('uses the store the application configured', function (): void {
    expect(app(StoresAudit::class))->toBeInstanceOf(DatabaseAuditLogger::class);

    config()->set('mcp-bridge.audit.driver', 'log');
    expect(app(StoresAudit::class))->toBeInstanceOf(LogAuditLogger::class);

    config()->set('mcp-bridge.audit.driver', 'none');
    expect(app(StoresAudit::class))->toBeInstanceOf(NullAuditLogger::class);
});

it('writes nothing when the application asked for no audit', function (): void {
    config()->set('mcp-bridge.audit.driver', 'none');

    callOverProtocol('list_courses');

    expect(McpAuditEntry::query()->count())->toBe(0);
});

it('does not fail a call when the audit cannot be written', function (): void {
    Schema::drop('mcp_bridge_audit');

    $body = callOverProtocol('list_courses', ['per_page' => 1]);

    // The work already happened; refusing now would record nothing and still
    // have done it.
    expect($body['result']['isError'] ?? false)->toBeFalse();
});

it('prunes what is older than the retention window', function (): void {
    foreach (['old' => 120, 'recent' => 1] as $id => $days) {
        McpAuditEntry::query()->create([
            'tool' => 'list_courses',
            'source' => 'route',
            'server' => 'default',
            'request_id' => $id,
            'arguments' => [],
            'outcome' => Outcome::Allowed->value,
            'created_at' => now()->subDays($days),
        ]);
    }

    $this->artisan('mcp-bridge:prune-audit --older-than=90')->assertSuccessful();

    expect(McpAuditEntry::query()->count())->toBe(1)
        ->and(McpAuditEntry::query()->first()?->getAttribute('request_id'))->toBe('recent');
});

it('records the status the source actually returned', function (): void {
    callOverProtocol('list_courses', ['per_page' => 2]);

    expect(McpAuditEntry::query()->first()?->getAttribute('status'))->toBe(200);
});

it('records no status for a call that never reached a source', function (): void {
    // The gate answered, so there is no response to have a status.
    callOverProtocol('purge_course');

    expect(McpAuditEntry::query()->first()?->getAttribute('status'))->toBeNull();
});

it('dispatches an event for a call that ran', function (): void {
    Event::fake([ToolCalled::class]);

    callOverProtocol('list_courses', ['per_page' => 2]);

    Event::assertDispatched(ToolCalled::class, function (ToolCalled $event): bool {
        return $event->outcome === Outcome::Allowed
            && $event->call->definition->name === 'list_courses'
            && $event->durationMs >= 0;
    });
});

it('dispatches an event for a call that was refused', function (): void {
    Event::fake([ToolCalled::class]);

    callOverProtocol('purge_course');

    Event::assertDispatched(ToolCalled::class, fn (ToolCalled $event): bool => $event->outcome === Outcome::Refused);
});

it('dispatches nothing extra when the audit is switched off', function (): void {
    // The two travel together, but the event is not the audit: an application
    // that keeps no trail still gets to watch its agents.
    config()->set('mcp-bridge.audit.driver', 'none');

    Event::fake([ToolCalled::class]);

    callOverProtocol('list_courses', ['per_page' => 2]);

    Event::assertDispatched(ToolCalled::class);
    expect(McpAuditEntry::query()->count())->toBe(0);
});

it('writes the row inline on stdio, where there is no response to defer past', function (): void {
    // A stdio server is one process for the whole session: a deferred row
    // would queue until it ended and be lost when the client disconnected.
    ServerKeyResolver::serving('default');

    app(StoresAudit::class)->record(new AuditEntry(
        tool: 'list_courses',
        source: 'route',
        server: 'default',
        actorType: TestUser::class,
        actorId: '1',
        client: 'stdio',
        requestId: 'local-1',
        arguments: [],
        outcome: Outcome::Allowed,
    ));

    // No flush: if this needed one, the row would not be there.
    expect(McpAuditEntry::query()->count())->toBe(1);
});

it('still defers on http, where the response is what the row should follow', function (): void {
    app(StoresAudit::class)->record(new AuditEntry(
        tool: 'list_courses',
        source: 'route',
        server: 'default',
        actorType: TestUser::class,
        actorId: '1',
        client: 'web',
        requestId: 'web-1',
        arguments: [],
        outcome: Outcome::Allowed,
    ));

    expect(McpAuditEntry::query()->count())->toBe(0);

    app(DeferredCallbackCollection::class)->invoke();

    expect(McpAuditEntry::query()->count())->toBe(1);
});
