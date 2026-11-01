<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Contracts\ConnectsRemoteServers;
use KayedSpace\McpBridge\Execution\RemoteExecutor;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Tests\Fixtures\FakeRemoteServers;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;

function compileRemote(array $settings = []): CompileResult
{
    config()->set('mcp-bridge.sources.remote.enabled', true);
    config()->set('mcp-bridge.sources.remote.servers.billing', [
        'url' => 'https://billing.test/mcp',
        'scopes' => ['billing:read'],
        'abilities' => ['use-billing'],
        ...$settings,
    ]);

    return app(ManifestCompiler::class)->compile();
}

beforeEach(function (): void {
    app()->singleton(ConnectsRemoteServers::class, fn (): FakeRemoteServers => new FakeRemoteServers);
});

it('re-exports the tools another server advertises', function (): void {
    expect(compileRemote()->names())->toBe(['billing_list_invoices', 'billing_void_invoice']);
});

it('reads the remote schema as it stands, nesting and all', function (): void {
    $input = toolNamed('billing_list_invoices', compileRemote())->input;

    expect($input->get('status')?->enum)->toBe(['open', 'paid'])
        ->and($input->get('customer')?->required)->toBeTrue()
        ->and($input->get('customer')?->type->value)->toBe('integer')
        ->and($input->get('filter')?->type->value)->toBe('object')
        ->and($input->get('filter.since')?->format)->toBe('date')
        ->and($input->get('tags')?->type->value)->toBe('array')
        ->and($input->get('tags.*')?->type->value)->toBe('string');
});

it('takes the remote safety hints, and assumes the worst where they are missing', function (): void {
    $result = compileRemote();

    expect(toolNamed('billing_list_invoices', $result)->safety->readOnly)->toBeTrue()
        ->and(toolNamed('billing_list_invoices', $result)->safety->destructive)->toBeFalse()
        // void_invoice says nothing, so it is treated as destructive.
        ->and(toolNamed('billing_void_invoice', $result)->safety->destructive)->toBeTrue()
        ->and(toolNamed('billing_void_invoice', $result)->authority->confirm)->toBeTrue();
});

it('carries this application authority, not the remote one', function (): void {
    $definition = toolNamed('billing_list_invoices', compileRemote());

    expect($definition->authority->scopes)->toBe(['billing:read'])
        ->and($definition->authority->abilities)->toBe(['use-billing'])
        ->and($definition->description)->toContain('Runs on the [billing] server');
});

it('re-exports only what the application chose', function (): void {
    expect(compileRemote(['only' => ['list_invoices']])->names())->toBe(['billing_list_invoices'])
        ->and(compileRemote(['except' => ['void_invoice']])->names())->toBe(['billing_list_invoices'])
        // An empty allowlist re-exports nothing; a wildcard, or no list at
        // all, re-exports everything the remote offers.
        ->and(compileRemote(['only' => []])->names())->not->toContain('billing_list_invoices')
        ->and(compileRemote(['only' => ['*']])->names())->toContain('billing_list_invoices', 'billing_void_invoice');
});

it('prefixes with the server, so two remotes can both have list_invoices', function (): void {
    expect(compileRemote(['prefix' => 'acme'])->names())->toContain('acme_list_invoices')
        ->and(compileRemote(['prefix' => ''])->names())->toContain('list_invoices');
});

it('refuses a remote with no authority declared for it', function (): void {
    $result = compileRemote(['scopes' => [], 'abilities' => []]);

    expect($result->names())->toBe([])
        ->and($result->refusals['remote:billing:list_invoices'][0]->message)
        ->toContain('needs both #[McpScope] and #[McpAbility]');
});

it('compiles the rest of the surface when a remote cannot be reached', function (): void {
    app()->singleton(ConnectsRemoteServers::class, fn (): FakeRemoteServers => new FakeRemoteServers(unreachable: true));

    defineApiRoutes();

    expect(compileRemote()->names())->toContain('list_courses')
        ->and(compileRemote()->names())->not->toContain('billing_list_invoices');
});

describe('calling', function (): void {
    it('forwards the call and passes the answer through', function (): void {
        $result = app(RemoteExecutor::class)->execute(callTool(
            toolNamed('billing_list_invoices', compileRemote()),
            ['customer' => 7],
            new TokenUser(['billing:read'], 3),
        ));

        expect($result->successful())->toBeTrue()
            ->and($result->payload)->toBe(['invoices' => [['id' => 1]]])
            ->and($result->meta['remote'])->toBe('billing');

        expect(app(ConnectsRemoteServers::class)->calls)->toBe([
            ['billing', 'list_invoices', ['customer' => 7]],
        ]);
    });

    it('reports an error the remote returned as an error', function (): void {
        $result = app(RemoteExecutor::class)->execute(callTool(
            toolNamed('billing_void_invoice', compileRemote()),
            ['id' => 1],
            new TokenUser(['billing:read'], 3),
        ));

        expect($result->successful())->toBeFalse()
            ->and($result->meta['code'])->toBe('MCP_BRIDGE_REMOTE_ERROR')
            ->and($result->payload['message'])->toBe('That invoice is already void.');
    });

    it('reports an unreachable remote as a gateway failure', function (): void {
        $definition = toolNamed('billing_list_invoices', compileRemote());

        app()->singleton(ConnectsRemoteServers::class, fn (): FakeRemoteServers => new FakeRemoteServers(unreachable: true));

        $result = app(RemoteExecutor::class)->execute(callTool($definition, [], new TokenUser(['billing:read'], 3)));

        expect($result->status)->toBe(502)
            ->and($result->meta['code'])->toBe('MCP_BRIDGE_REMOTE_UNAVAILABLE');
    });
});

it('drops a hidden field rather than sending this application\'s user id to another server', function (): void {
    config()->set('mcp-bridge.security.fill_from_auth', ['customer']);

    $tool = toolNamed('billing_list_invoices', compileRemote());

    expect($tool->input->get('customer')?->hidden)->toBeTrue()
        ->and($tool->input->get('customer')?->filledFrom)->toBeNull();

    app(RemoteExecutor::class)->execute(callTool($tool, ['customer' => 7, 'status' => 'open'], new TokenUser(['billing:read'], 3)));

    expect(app(ConnectsRemoteServers::class)->calls[0][2])->toBe(['status' => 'open']);
});
