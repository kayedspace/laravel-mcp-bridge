<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    defineApiRoutes();
    defineQueryRoutes();
});

it('finds a tool, and says what it found when it cannot', function (): void {
    expect($this->mcpTool('list_courses')->source)->toBe('route');

    expect(fn () => $this->mcpTool('no_such_tool'))
        ->toThrow(AssertionFailedError::class, 'No tool named [no_such_tool]');
});

it('asserts what is exposed and what is not', function (): void {
    $this->assertToolExists('list_courses')
        ->assertToolMissing('internal_course');
});

it('asserts that a candidate was refused, and why', function (): void {
    $this->assertToolRefused('route:api.v1.courses.internal:GET', 'MCP_BRIDGE_NOT_EXPOSED')
        ->assertToolRefused('route:api.v1.courses.export:GET', 'MCP_BRIDGE_IGNORED');
});

it('asserts the agent-facing inputs', function (): void {
    $this->assertToolInputs('create_course', ['title', 'status', 'price']);

    expect(fn () => $this->assertToolInputs('create_course', ['nonexistent']))
        ->toThrow(AssertionFailedError::class, 'has no input [nonexistent]');
});

it('asserts a list tool still offers the filters its endpoint allows', function (): void {
    $this->assertQueryInputs(
        'list_catalog',
        filters: ['title', 'status', 'author'],
        sorts: ['title', '-created_at'],
        includes: ['lessons', 'lessonsCount'],
    );

    // The drift this is for: a filter the endpoint has and the agent does not.
    expect(fn () => $this->assertQueryInputs('list_catalog', filters: ['invented']))
        ->toThrow(AssertionFailedError::class, 'does not offer the filter [invented]');
});

it('asserts safety, confirmation and scopes', function (): void {
    $this->assertToolReadOnly('list_courses')
        ->assertToolNeedsConfirmation('purge_course');

    expect(fn () => $this->assertToolReadOnly('create_course'))
        ->toThrow(AssertionFailedError::class, 'is not read-only');
});

describe('calling', function (): void {
    it('runs a tool as a given user and shapes the result', function (): void {
        $result = $this->callMcpTool('list_courses', ['per_page' => 2], as: new TestUser);

        expect($result->isError)->toBeFalse()
            ->and($result->structured['data'])->toHaveCount(2);
    });

    it('takes the user from actingAs when none is named', function (): void {
        $this->actingAs(new TestUser, 'mcp-bridge');

        expect($this->callMcpTool('get_course', ['course' => 3])->structured['data']['id'])->toBe(3);
    });

    it('refuses to guess who is calling', function (): void {
        expect(fn () => $this->callMcpTool('list_courses'))
            ->toThrow(AssertionFailedError::class, 'needs someone to call it as');
    });

    it('goes through the gate, so a test cannot prove more than the agent gets', function (): void {
        $result = $this->callMcpTool('purge_course', as: new TokenUser([], 4));

        expect($result->errorCode)->toBe('MCP_BRIDGE_CONFIRMATION_REQUIRED');
    });

    it('walks the confirmation handshake the way an agent does', function (): void {
        $result = $this->confirmMcpTool('purge_course', as: new TokenUser([], 4));

        expect($result->isError)->toBeFalse()
            ->and($result->structured['data']['purged'])->toBe(3);
    });

    it('replays a write called twice with one idempotency key', function (): void {
        $first = $this->callMcpTool('create_course', ['title' => 'Once', 'status' => 'draft'], as: new TestUser, idempotencyKey: 'once');
        $again = $this->callMcpTool('create_course', ['title' => 'Once', 'status' => 'draft'], as: new TestUser, idempotencyKey: 'once');

        expect($first->meta)->not->toHaveKey('replayed')
            ->and($again->meta['replayed'] ?? false)->toBeTrue()
            ->and($again->structured)->toBe($first->structured);
    });

    it('records what it did, because it is the real pipeline', function (): void {
        createAuditTable();

        $this->callMcpTool('list_courses', as: new TestUser);

        expect(McpAuditEntry::query()->first()?->getAttribute('client'))->toBe('tests');
    });
});

it('hands back the agent contract, sorted and free of manifest internals', function () {
    $contract = $this->mcpContract();

    expect(array_keys($contract))->toBe(collect(array_keys($contract))->sort()->values()->all())
        ->and($contract['list_courses'])->toHaveKeys(['description', 'inputSchema', 'annotations'])
        // The things that move when a class is renamed stay out of it.
        ->and($contract['list_courses'])->not->toHaveKeys(['candidate_id', 'target', 'origin']);
});
