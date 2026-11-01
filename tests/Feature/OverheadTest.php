<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Execution\ToolRunner;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Tests\Fixtures\Course;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;

/**
 * What this package costs on top of the request it is wrapping.
 *
 * NFR-1 asks for 5 ms p95, and a test asserting 5 ms on a shared CI runner
 * is a test that fails for reasons nobody caused and gets muted within a
 * month. So this is a tripwire rather than a measurement: generous enough
 * never to cry wolf, tight enough to catch an order of magnitude. The real
 * figure is printed, so the number is visible even though it is not asserted.
 *
 * Medians, not means, because one scheduler hiccup in twenty samples moves a
 * mean and not a median, and the hiccup is not the overhead.
 */
function medianNanos(Closure $operation, int $samples = 15): float
{
    $timings = [];

    for ($i = 0; $i < $samples; $i++) {
        $started = hrtime(true);
        $operation();
        $timings[] = hrtime(true) - $started;
    }

    sort($timings);

    return (float) $timings[intdiv(count($timings), 2)];
}

beforeEach(function (): void {
    config(['mcp-bridge.mode' => 'opt-in']);

    Schema::create('courses', function ($table): void {
        $table->id();
        $table->string('title');
        $table->string('status')->default('draft');
        $table->softDeletes();
        $table->timestamps();
    });

    Course::query()->insert([
        ['id' => 1, 'title' => 'Cardiology', 'status' => 'published'],
        ['id' => 2, 'title' => 'Neurology', 'status' => 'published'],
    ]);

    defineApiRoutes();
});

it('costs little enough over the same REST request to be worth wrapping', function (): void {
    test()->actingAs(new TestUser, 'mcp-bridge');

    app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);

    // Both over HTTP, because that is the comparison the claim makes and the
    // one a consumer feels. Measuring an internal sub-request against a test
    // client's full kernel pass reports the tool as cheaper than the route it
    // calls, which is true of the measurement and not of anything else.
    $rest = fn (): mixed => test()->getJson('api/v1/courses?per_page=2')->assertOk();

    $tool = fn (): mixed => test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'list_courses',
            'arguments' => ['per_page' => 2],
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
                'io.modelcontextprotocol/clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
            ],
        ],
    ], [
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'tools/call',
        'Mcp-Name' => 'list_courses',
    ])->assertOk();

    // The first of anything pays for a container that has not resolved yet,
    // which is not the overhead anyone is asking about.
    $rest();
    $tool();

    $restMedian = medianNanos($rest);
    $toolMedian = medianNanos($tool);
    $overhead = ($toolMedian - $restMedian) / 1_000_000;

    fwrite(STDERR, sprintf(
        "\n  REST %.2f ms, tool %.2f ms, overhead %.2f ms\n",
        $restMedian / 1_000_000,
        $toolMedian / 1_000_000,
        $overhead,
    ));

    // A tool call is the REST request plus JSON-RPC, the gate, the shaping
    // and the audit entry. Four times the request it wraps, or 25 ms,
    // whichever is larger: generous enough to pass on a loaded runner, tight
    // enough to fail if a cached lookup turns into a query per call.
    expect($toolMedian)->toBeLessThan(max($restMedian * 4, 25_000_000));
})->skip(fn (): bool => (bool) ini_get('pcov.enabled'), 'A coverage driver slows every call, so a timing says nothing.');

it('keeps the audit off the path the agent waits on', function (): void {
    createAuditTable();

    $definition = toolNamed('list_courses');
    $actor = new TestUser;

    $call = fn (): mixed => app(ToolRunner::class)->run(new ToolCall(
        definition: $definition,
        arguments: ['per_page' => 2],
        actor: $actor,
        requestId: 'audit-overhead',
    ));

    $call();

    config(['mcp-bridge.audit.driver' => 'none']);
    $without = medianNanos($call);

    config(['mcp-bridge.audit.driver' => 'database']);
    $with = medianNanos($call);

    // The row is written after the response, so turning the audit on should
    // cost building an entry and not inserting one. If this ever fails, the
    // write has come back onto the critical path.
    expect($with)->toBeLessThan(max($without * 2, 20_000_000));
});
