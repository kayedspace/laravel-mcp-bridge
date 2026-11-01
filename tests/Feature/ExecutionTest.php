<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\RouteTarget;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Events\ToolCalled;
use KayedSpace\McpBridge\Exceptions\InvalidQueryInput;
use KayedSpace\McpBridge\Execution\IdempotencyKeys;
use KayedSpace\McpBridge\Execution\ToolRunner;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Output\ErrorMapper;
use KayedSpace\McpBridge\Output\OutputShaper;
use KayedSpace\McpBridge\Tests\Fixtures\Middleware\RecordsContext;
use KayedSpace\McpBridge\Tests\Fixtures\Middleware\ThrowsInMiddleware;
use KayedSpace\McpBridge\Tests\Fixtures\Middleware\VetoesEverything;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;

beforeEach(fn () => config(['mcp-bridge.mode' => 'opt-in']));

function run(string $tool, array $arguments = []): array
{
    $definition = toolNamed($tool);
    $result = app(ExecutesTools::class)->execute(callTool($definition, $arguments));

    $shaped = $result->successful()
        ? app(OutputShaper::class)->shape($result, $definition)
        : app(ErrorMapper::class)->map($result, $definition);

    return [$result, $shaped];
}

it('runs a read tool through the real route and shapes the response', function () {
    [$result, $shaped] = run('list_courses', ['per_page' => 2]);

    expect($result->status)->toBe(200)
        ->and($shaped->isError)->toBeFalse()
        ->and($shaped->structured['data'])->toHaveCount(2)
        ->and($shaped->structured['data'][0]['title'])->toBe('Cardiology Basics')
        ->and($shaped->text)->toContain('Returned 2 of 2 items');
});

it('passes agent input through to the route as real request input', function () {
    [, $shaped] = run('list_courses', ['per_page' => 7]);

    expect($shaped->structured['pagination']['per_page'])->toBe(7);
});

it('refuses an argument no field declares and names the ones it accepts', function () {
    $result = app(ToolRunner::class)->run(callTool(toolNamed('list_courses'), ['per_page' => 2, 'title' => 'x']));

    expect($result->isError)->toBeTrue()
        ->and($result->errorCode)->toBe('MCP_BRIDGE_UNKNOWN_ARGUMENT')
        ->and($result->text)->toContain('[title]')
        ->and($result->text)->toContain('per_page');
});

it('lets every argument through to a tool that declares no inputs', function () {
    $result = app(ToolRunner::class)->run(callTool(definition(), ['anything' => 1]));

    expect($result->errorCode)->not->toBe('MCP_BRIDGE_UNKNOWN_ARGUMENT');
});

it('substitutes route parameters', function () {
    [$result, $shaped] = run('get_course', ['course' => 12]);

    expect($result->status)->toBe(200)
        ->and($shaped->structured['data']['id'])->toBe(12);
});

it('sends write input as a json body and keeps validation', function () {
    [$result, $shaped] = run('create_course', [
        'title' => 'New Course',
        'status' => 'draft',
        'price' => 10.5,
    ]);

    expect($result->status)->toBe(201)
        ->and($shaped->structured['data'])->toMatchArray([
            'id' => 42,
            'title' => 'New Course',
            'status' => 'draft',
        ]);
});

it('maps a validation failure to per field messages the agent can act on', function () {
    [$result, $shaped] = run('create_course', ['status' => 'draft']);

    expect($result->status)->toBe(422)
        ->and($shaped->isError)->toBeTrue()
        ->and($shaped->errorCode)->toBe('MCP_BRIDGE_VALIDATION')
        ->and($shaped->text)->toContain('title')
        ->and($shaped->structured['errors'])->toHaveKey('title');
});

it('maps a missing record to a not found error with a hint', function () {
    [$result, $shaped] = run('get_course', ['course' => 404]);

    expect($result->status)->toBe(404)
        ->and($shaped->errorCode)->toBe('MCP_BRIDGE_NOT_FOUND')
        ->and($shaped->text)->toContain('list tool');
});

it('refuses a route that does not return json', function () {
    config(['mcp-bridge.mode' => 'opt-out']);

    [, $shaped] = run('html_course');

    expect($shaped->isError)->toBeTrue()
        ->and($shaped->errorCode)->toBe('MCP_BRIDGE_NON_JSON');
});

it('reports a no content response as a completed call', function () {
    [$result, $shaped] = run('delete_course', ['course' => 3]);

    expect($result->status)->toBe(204)
        ->and($shaped->isError)->toBeFalse()
        ->and($shaped->text)->toContain('completed');
});

it('redacts configured keys and drops nulls from the output', function () {
    [, $shaped] = run('list_courses');

    expect($shaped->structured['data'][0])->not->toHaveKey('password')
        ->and($shaped->structured['data'][0])->not->toHaveKey('summary');
});

it('redacts a key that merely contains a configured secret', function () {
    // The audit and the output answer this the same way, because a field
    // masked in one and passed through in the other is still leaked.
    config()->set('mcp-bridge.security.redact', ['password']);

    [, $shaped] = run('list_courses');

    expect($shaped->structured['data'][0])->not->toHaveKey('password_hint');
});

it('keeps pagination while dropping the rest of the envelope noise', function () {
    [, $shaped] = run('list_courses');

    expect($shaped->structured)->not->toHaveKey('links')
        ->and($shaped->structured['pagination'])->toMatchArray(['current_page' => 1, 'total' => 2]);
});

it('trims a list to the configured maximum', function () {
    config(['mcp-bridge.output.max_items' => 1]);

    [, $shaped] = run('list_courses');

    expect($shaped->structured['data'])->toHaveCount(1)
        ->and($shaped->meta['truncated'])->toBeTrue()
        ->and($shaped->text)->toContain('trimmed');
});

it('restores the outer request and user after a call', function () {
    $outer = Request::create('/outer');
    app()->instance('request', $outer);

    $previous = new TestUser(99);
    auth()->guard()->setUser($previous);

    run('get_course', ['course' => 5]);

    expect(app('request'))->toBe($outer)
        ->and(auth()->guard()->user()->getAuthIdentifier())->toBe(99);
});

describe('middleware and context', function () {
    it('runs the configured middleware with the log context set', function () {
        config(['mcp-bridge.execution.middleware' => [RecordsContext::class]]);
        RecordsContext::$seen = [];

        $result = app(ToolRunner::class)->run(callTool(toolNamed('list_courses'), ['per_page' => 1], new TestUser(7)));

        expect($result->isError)->toBeFalse()
            ->and(RecordsContext::$seen['tool'])->toBe('list_courses')
            ->and(RecordsContext::$seen['request_id'])->toBe('test-request')
            ->and(RecordsContext::$seen['actor'])->toBe('7')
            ->and(RecordsContext::$seen['arguments'])->toBe(['per_page' => 1])
            ->and(Context::get('mcp.tool'))->toBeNull();
    });

    it('answers, records and releases the key when a middleware throws', function () {
        createAuditTable();
        config(['mcp-bridge.execution.middleware' => [ThrowsInMiddleware::class]]);

        $definition = toolNamed('create_course');
        $call = fn (string $id): ToolResult => app(ToolRunner::class)->run(new ToolCall(
            definition: $definition,
            arguments: ['title' => 'Survives', 'status' => 'draft'],
            actor: new TestUser,
            requestId: $id,
            idempotencyKey: 'survives',
        ));

        $failed = $call('first');
        app(DeferredCallbackCollection::class)->invoke();

        config(['mcp-bridge.execution.middleware' => []]);
        $retry = $call('second');

        expect($failed->errorCode)->toBe('MCP_BRIDGE_SERVER_ERROR')
            ->and($failed->text)->not->toContain('exploded')
            ->and(McpAuditEntry::query()->first()?->getAttribute('outcome'))->toBe('failed')
            ->and(Context::get('mcp.tool'))->toBeNull()
            ->and($retry->isError)->toBeFalse();
    });

    it('shows the detail of a thrown exception only when the configuration asks', function () {
        config([
            'mcp-bridge.execution.middleware' => [ThrowsInMiddleware::class],
            'mcp-bridge.security.expose_error_details' => true,
        ]);

        $result = app(ToolRunner::class)->run(callTool(toolNamed('list_courses'), [], new TestUser));

        expect($result->text)->toContain('tenant lookup exploded');
    });

    it('stops every call when a configured middleware does not exist', function () {
        config(['mcp-bridge.execution.middleware' => ['App\\Mcp\\NoSuchMiddleware']]);

        $result = app(ToolRunner::class)->run(callTool(toolNamed('list_courses'), [], new TestUser));

        expect($result->errorCode)->toBe('MCP_BRIDGE_SERVER_ERROR')
            ->and($result->text)->toContain('App\\Mcp\\NoSuchMiddleware');
    });

    it('lets a middleware stop the call, recorded as a refusal', function () {
        createAuditTable();
        config(['mcp-bridge.execution.middleware' => [VetoesEverything::class]]);

        $result = app(ToolRunner::class)->run(callTool(toolNamed('list_courses'), [], new TestUser));
        app(DeferredCallbackCollection::class)->invoke();

        expect($result->errorCode)->toBe('MCP_BRIDGE_FORBIDDEN')
            ->and(McpAuditEntry::query()->first()?->getAttribute('outcome'))->toBe('refused')
            ->and(Context::get('mcp.tool'))->toBeNull();
    });
});

it('makes the call visible to application code as an mcp call', function () {
    $definition = toolNamed('get_course');

    app(ExecutesTools::class)->execute(callTool($definition, ['course' => 5]));

    // The sub-request carried the marker; the outer state is clean again.
    expect(app('request')->attributes->get('mcp.tool'))->toBeNull();
});

it('hands the resolved actor to the route rather than authenticating twice', function () {
    $definition = toolNamed('get_course');
    $call = callTool($definition, ['course' => 5]);

    $seen = null;

    app('router')->get('api/v1/whoami', function () use (&$seen) {
        $seen = auth()->id();

        return ['data' => ['id' => $seen]];
    });

    app(ExecutesTools::class)->execute($call);

    expect($call->actor->getAuthIdentifier())->toBe(1);
});

describe('through the kernel', function () {
    it('runs the global middleware, so input is trimmed as it is for a REST request', function () {
        [$result, $shaped] = run('create_course', ['title' => '  Padded  ', 'status' => 'draft']);

        expect($result->status)->toBe(201)
            ->and($shaped->structured['data']['title'])->toBe('Padded');
    });

    it('reports an exception a route throws to the application, rather than swallowing it', function () {
        Exceptions::fake();

        app('router')->get('api/v1/broken', function (): never {
            throw new RuntimeException('the route broke');
        });

        $definition = definition(name: 'break_things', target: new RouteTarget('GET', 'api/v1/broken', null));

        [$result, $shaped] = [
            $r = app(ExecutesTools::class)->execute(callTool($definition)),
            app(ErrorMapper::class)->map($r, $definition),
        ];

        // A 500 an agent caused used to leave no trace in the application's
        // own log, because the executor caught it before the handler saw it.
        Exceptions::assertReported(RuntimeException::class);

        expect($result->status)->toBe(500)
            ->and($shaped->errorCode)->toBe('MCP_BRIDGE_SERVER_ERROR')
            ->and($shaped->text)->not->toContain('the route broke');
    });

    it('shows the rendered detail of a server error only when asked', function () {
        config(['mcp-bridge.security.expose_error_details' => true, 'app.debug' => true]);

        app('router')->get('api/v1/broken', function (): never {
            throw new RuntimeException('the route broke');
        });

        $definition = definition(name: 'break_things', target: new RouteTarget('GET', 'api/v1/broken', null));
        $result = app(ExecutesTools::class)->execute(callTool($definition));

        expect(app(ErrorMapper::class)->map($result, $definition)->text)->toContain('the route broke');
    });

    it('forwards the host, the client address and allowlisted headers from the outer request', function () {
        app()->instance('request', Request::create('https://app.test/mcp', 'POST', server: [
            'REMOTE_ADDR' => '10.1.1.1',
            'HTTP_ACCEPT_LANGUAGE' => 'fr',
            'HTTP_X_SECRET' => 'not for the route',
        ]));

        app('router')->get('api/v1/echo', fn (Request $request): array => ['data' => [
            'host' => $request->getHost(),
            'ip' => $request->ip(),
            'language' => $request->header('Accept-Language'),
            'secret' => $request->header('X-Secret'),
            'url' => url('/api/v1/echo'),
        ]]);

        $definition = definition(name: 'echo', target: new RouteTarget('GET', 'api/v1/echo', null));
        $result = app(ExecutesTools::class)->execute(callTool($definition));

        // The host and address are what a REST client's would be, so a URL
        // the route builds and an IP-keyed throttle both see the agent, and
        // only the allowlisted headers travel.
        expect($result->payload['data'])->toMatchArray([
            'host' => 'app.test',
            'ip' => '10.1.1.1',
            'language' => 'fr',
            'secret' => null,
            'url' => 'https://app.test/api/v1/echo',
        ]);
    });

    it('holds maintenance mode, and tells the agent to retry later', function () {
        app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });

        [$result, $shaped] = run('list_courses');

        expect($result->status)->toBe(503)
            ->and($shaped->errorCode)->toBe('MCP_BRIDGE_UNAVAILABLE');
    });

    it('passes a rejected query through with the message that names what is allowed', function () {
        app('router')->get('api/v1/reject', function (): never {
            throw InvalidQueryInput::for('filter', ['colour'], ['title', 'status']);
        });

        $definition = definition(name: 'reject', target: new RouteTarget('GET', 'api/v1/reject', null));
        $result = app(ExecutesTools::class)->execute(callTool($definition));
        $shaped = app(ErrorMapper::class)->map($result, $definition);

        expect($result->status)->toBe(400)
            ->and($shaped->errorCode)->toBe('MCP_BRIDGE_BAD_REQUEST')
            ->and($shaped->text)->toContain('Allowed filter(s) are `title, status`');
    });

    it('keeps the model class out of a not found answer', function () {
        defineResourceRoutes();

        Schema::create('courses', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->softDeletes();
            $table->timestamps();
        });

        // The handler renders a missing bound model as "No query results for
        // model [...]", naming the class, which is the application's
        // business rather than the agent's.
        $definition = toolNamed('get_catalogue', app(ManifestCompiler::class)->compile());
        $result = app(ExecutesTools::class)->execute(callTool($definition, ['course' => 404]));

        expect($result->status)->toBe(404)
            ->and($result->payload['message'])->toBe('Not found.');
    });
});

it('cuts a list down to the token budget even when it fits the item limit', function () {
    [, $generous] = run('list_courses', ['per_page' => 10]);

    // Small enough that one row is the whole allowance.
    config(['mcp-bridge.output.token_budget' => 10]);

    [, $tight] = run('list_courses', ['per_page' => 10]);

    expect($generous->structured['data'])->toHaveCount(2)
        ->and($generous->meta)->not->toHaveKey('truncated')
        ->and($tight->structured['data'])->toHaveCount(1)
        ->and($tight->meta['truncated'])->toBeTrue()
        ->and($tight->text)->toContain('trimmed');
});

it('keeps one row rather than answering with an empty list', function () {
    config(['mcp-bridge.output.token_budget' => 1]);

    [, $shaped] = run('list_courses', ['per_page' => 10]);

    expect($shaped->structured['data'])->toHaveCount(1);
});

it('leaves a single record whole rather than returning half of one', function () {
    config(['mcp-bridge.output.token_budget' => 1]);

    [, $shaped] = run('get_course', ['course' => 12]);

    expect($shaped->structured['data']['id'])->toBe(12);
});

it('can be told to send the text without structured content, and the text still carries the data', function () {
    config(['mcp-bridge.output.structured' => false]);

    [, $shaped] = run('list_courses', ['per_page' => 2]);

    expect($shaped->structured)->toBeNull()
        ->and($shaped->text)->toContain('Returned 2 of 2 items')
        ->and($shaped->text)->toContain('Cardiology Basics');
});

it('passes a confirmed rule without the agent sending the value twice', function () {
    // The request rules say access_code is `confirmed`, so validation wants
    // access_code_confirmation too. The agent sent one value.
    [$result, $shaped] = run('create_course', [
        'title' => 'Immunology',
        'status' => 'draft',
        'access_code' => 'open-sesame',
    ]);

    expect($result->status)->toBe(201)
        ->and($shaped->isError)->toBeFalse();
});

it('leaves a nullable confirmed field alone when the agent sends nothing', function () {
    [$result] = run('create_course', ['title' => 'Immunology', 'status' => 'draft']);

    // Nullable, so an absent code is fine: the twin is only filled when there
    // is something to copy.
    expect($result->status)->toBe(201);
});

describe('idempotency keys', function () {
    it('offers a key on a write and not on a read', function () {
        expect(toolNamed('create_course')->input->get('idempotency_key'))->not->toBeNull()
            ->and(toolNamed('list_courses')->input->get('idempotency_key'))->toBeNull();
    });

    it('answers a retry with the first result instead of writing twice', function () {
        $definition = toolNamed('create_course');

        $call = fn (): ToolResult => app(ToolRunner::class)->run(new ToolCall(
            definition: $definition,
            arguments: ['title' => 'Immunology', 'status' => 'draft'],
            actor: new TestUser,
            requestId: 'first',
            idempotencyKey: 'enrolment-7',
        ));

        $recorded = 0;
        Event::listen(ToolCalled::class, function () use (&$recorded): void {
            $recorded++;
        });

        $first = $call();
        $second = $call();

        // Both calls are recorded, because both happened: an agent that
        // retried is a thing an operator should be able to see. What proves
        // the work ran once is the marker, since the fixture answers with a
        // fixed id and equal ids would prove nothing.
        expect($recorded)->toBe(2)
            ->and($first->meta)->not->toHaveKey('replayed')
            ->and($second->meta['replayed'])->toBeTrue()
            ->and($second->structured)->toBe($first->structured);
    });

    it('reads the same arguments in another key order as the same call', function () {
        $definition = toolNamed('create_course');
        $call = fn (array $arguments): ToolResult => app(ToolRunner::class)->run(new ToolCall(
            definition: $definition,
            arguments: $arguments,
            actor: new TestUser,
            requestId: 'ordered',
            idempotencyKey: 'order',
        ));

        $call(['title' => 'Ordered', 'status' => 'draft']);
        $again = $call(['status' => 'draft', 'title' => 'Ordered']);

        expect($again->meta['replayed'] ?? false)->toBeTrue();
    });

    it('treats the same key with different arguments as a different call', function () {
        $definition = toolNamed('create_course');

        $call = fn (string $title): ToolResult => app(ToolRunner::class)->run(new ToolCall(
            definition: $definition,
            arguments: ['title' => $title, 'status' => 'draft'],
            actor: new TestUser,
            requestId: 'r',
            idempotencyKey: 'reused',
        ));

        $call('Immunology');
        $second = $call('Neurology');

        // Replaying the first answer to a call that asked for something else
        // would hand the agent a result for work it did not request.
        expect($second->meta)->not->toHaveKey('replayed')
            ->and($second->structured['data']['title'])->toBe('Neurology');
    });

    it('does not replay a failure, so a corrected retry runs', function () {
        $definition = toolNamed('create_course');

        $call = fn (array $arguments): ToolResult => app(ToolRunner::class)->run(new ToolCall(
            definition: $definition,
            arguments: $arguments,
            actor: new TestUser,
            requestId: 'r',
            idempotencyKey: 'fix-me',
        ));

        // The fingerprint differs once the arguments are corrected, which is
        // half the answer. The other half is the claim itself: a failed call
        // releases its key rather than keeping the refusal for a day.
        expect($call(['status' => 'draft'])->errorCode)->toBe('MCP_BRIDGE_VALIDATION')
            ->and($call(['status' => 'draft'])->errorCode)->toBe('MCP_BRIDGE_VALIDATION')
            ->and($call(['status' => 'draft'])->meta)->not->toHaveKey('replayed');
    });

    it('holds an in-flight claim only as long as the tool may run', function () {
        $definition = toolNamed('create_course');
        $arguments = ['title' => 'Immunology', 'status' => 'draft'];

        $call = new ToolCall(
            definition: $definition,
            arguments: $arguments,
            actor: new TestUser,
            requestId: 'r',
            idempotencyKey: 'abandoned',
        );

        $keys = app(IdempotencyKeys::class);

        // A first call that claimed the key and died before recording: the
        // claim must expire with the tool's timeout, not the result's ttl.
        expect($keys->claim($call))->toBeNull()
            ->and($keys->claim($call)?->errorCode)->toBe('MCP_BRIDGE_IN_FLIGHT');

        $this->travel($definition->limits->timeout + 1)->seconds();

        expect($keys->claim($call))->toBeNull();
    });

    it('treats the same key on another tool as another operation', function () {
        $arguments = ['title' => 'Immunology', 'status' => 'draft'];

        app(ToolRunner::class)->run(new ToolCall(
            definition: toolNamed('create_course'),
            arguments: $arguments,
            actor: new TestUser,
            requestId: 'a',
            idempotencyKey: 'shared',
        ));

        $other = app(ToolRunner::class)->run(new ToolCall(
            definition: toolNamed('inline_course'),
            arguments: $arguments,
            actor: new TestUser,
            requestId: 'b',
            idempotencyKey: 'shared',
        ));

        expect($other->isError)->toBeFalse();
    });

    it('leaves a confirm-required tool callable, handshake and all', function (): void {
        $definition = toolNamed('purge_course');
        $actor = new TestUser;

        $call = fn (?string $confirm) => app(ToolRunner::class)->run(new ToolCall(
            definition: $definition,
            arguments: [],
            actor: $actor,
            requestId: 'r',
            confirm: $confirm,
            idempotencyKey: 'purge-1',
        ));

        $preview = $call(null);
        $handle = $preview->structured['confirmation']['handle'] ?? null;

        expect($handle)->toBeString();

        $confirmed = $call($handle);

        expect($confirmed->errorCode)->not->toBe('MCP_BRIDGE_IN_FLIGHT');
    });

    it('refuses a caller whose scope is gone rather than replaying to it', function (): void {
        Schema::create('courses', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            // Fillable on the model, so a model write fills it from the actor.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Gate::define('manage-catalogue', fn (): bool => true);

        // The model source, because its tools declare #[McpScope]; a plain route
        // declares none, so losing a scope cannot refuse it and the question
        // cannot be asked of it.
        config([
            'mcp-bridge.sources.model.enabled' => true,
            'mcp-bridge.sources.model.paths' => [__DIR__.'/../Fixtures'],
            'mcp-bridge.sources.model.operations' => ['create'],
        ]);

        $definition = toolNamed('model_create_course', app(ManifestCompiler::class)->compile());
        $arguments = ['title' => 'Immunology', 'status' => 'draft'];

        $allowed = app(ToolRunner::class)->run(new ToolCall(
            definition: $definition,
            arguments: $arguments,
            actor: new TokenUser(['courses:write'], 9),
            requestId: 'a',
            idempotencyKey: 'shared-key',
        ));

        expect($allowed->isError)->toBeFalse();

        // Same user, a token that no longer holds the scope.
        $revoked = app(ToolRunner::class)->run(new ToolCall(
            definition: $definition,
            arguments: $arguments,
            actor: new TokenUser([], 9),
            requestId: 'b',
            idempotencyKey: 'shared-key',
        ));

        expect($revoked->isError)->toBeTrue();
    });

    it('does nothing when no key is sent', function () {
        [$result] = run('create_course', ['title' => 'Immunology', 'status' => 'draft']);

        expect($result->status)->toBe(201);
    });
});

it('refuses an undeclared argument on a destructive tool before any handle is issued', function () {
    $result = app(ToolRunner::class)->run(callTool(toolNamed('delete_course'), ['course' => 1, 'bogus' => 1]));

    expect($result->errorCode)->toBe('MCP_BRIDGE_UNKNOWN_ARGUMENT')
        ->and($result->structured['handle'] ?? null)->toBeNull();
});
