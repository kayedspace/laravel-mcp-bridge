<?php

declare(strict_types=1);

use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Contracts\AuthorizesTools;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Security\ActorResolver;
use KayedSpace\McpBridge\Security\ConfirmationHandles;
use KayedSpace\McpBridge\Security\ScopeGate;
use KayedSpace\McpBridge\Server\GeneratedTool;
use KayedSpace\McpBridge\Server\ServerKeyResolver;
use KayedSpace\McpBridge\Tests\Fixtures\EnrolmentController;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use Laravel\Mcp\Request as McpRequest;

beforeEach(function () {
    config(['mcp-bridge.mode' => 'opi want also better statistics in the home page ( with filter ability ) and the appointemnts page and my cases page and clinical tasks page and so on
  Alomst every page should hold statistics and chart as it seems to be logical with filtering and periods and so on

  and enrich the home statistics alsot-in']);

    Gate::define('run-reports', fn ($user): bool => true);
});

it('hides a tool whose scope the caller does not hold', function () {
    $gate = app(AuthorizesTools::class);
    $report = toolNamed('list_reports');

    expect($gate->visible($report, new TokenUser(['reports:read'])))->toBeTrue()
        ->and($gate->visible($report, new TokenUser([])))->toBeFalse()
        ->and($gate->visible($report, null))->toBeFalse();
});

it('rejects the call too, because hiding a tool is not enforcement', function () {
    $refusal = app(AuthorizesTools::class)->check(
        callTool(toolNamed('list_reports'), actor: new TokenUser([])),
    );

    expect($refusal)->not->toBeNull()
        ->and($refusal->errorCode)->toBe('MCP_BRIDGE_SCOPE')
        ->and($refusal->isError)->toBeTrue();
});

it('fails closed when the guard cannot report token abilities', function () {
    // A plain user has no tokenCan(), so a declared scope is unverifiable and
    // the call is refused rather than assumed to be allowed.
    $refusal = app(AuthorizesTools::class)->check(
        callTool(toolNamed('list_reports'), actor: new TestUser),
    );

    expect($refusal->errorCode)->toBe('MCP_BRIDGE_SCOPE')
        ->and($refusal->text)->toContain('cannot report token abilities');
});

it('lets a call through when the scope and ability both hold', function () {
    expect(app(AuthorizesTools::class)->check(
        callTool(toolNamed('list_reports'), actor: new TokenUser(['reports:read'])),
    ))->toBeNull();
});

describe('the access gate', function () {
    it('changes nothing until the application defines it', function () {
        expect(app(AuthorizesTools::class)->visible(toolNamed('list_reports'), new TokenUser(['reports:read'])))->toBeTrue();
    });

    it('hides every tool and refuses every call for an account it denies', function () {
        Gate::define(ScopeGate::ACCESS, fn ($user): bool => false);

        $gate = app(AuthorizesTools::class);
        $report = toolNamed('list_reports');
        $refusal = $gate->check(callTool($report, actor: new TokenUser(['reports:read'])));

        expect($gate->visible($report, new TokenUser(['reports:read'])))->toBeFalse()
            ->and($refusal->errorCode)->toBe('MCP_BRIDGE_ACCESS');
    });

    it('is asked with the server, so one server can stay open and another closed', function () {
        Gate::define(ScopeGate::ACCESS, fn ($user, string $server): bool => $server === 'default');

        $gate = app(AuthorizesTools::class);

        expect($gate->visible(definition(name: 'open', server: 'default'), new TokenUser(['*'])))->toBeTrue()
            ->and($gate->visible(definition(name: 'closed', server: 'ops'), new TokenUser(['*'])))->toBeFalse();
    });
});

it('refuses a call whose gate ability is denied', function () {
    Gate::define('run-reports', fn ($user): bool => false);

    $refusal = app(AuthorizesTools::class)->check(
        callTool(toolNamed('list_reports'), actor: new TokenUser(['reports:read'])),
    );

    expect($refusal->errorCode)->toBe('MCP_BRIDGE_FORBIDDEN')
        ->and($refusal->text)->toContain('run-reports');
});

it('enforces a per tool rate limit', function () {
    $gate = app(AuthorizesTools::class);
    $definition = toolNamed('list_reports');
    $actor = new TokenUser(['reports:read'], 101);

    // The fixture declares #[McpRateLimit(2)].
    expect($gate->check(callTool($definition, actor: $actor)))->toBeNull()
        ->and($gate->check(callTool($definition, actor: $actor)))->toBeNull();

    $refusal = $gate->check(callTool($definition, actor: $actor));

    expect($refusal->errorCode)->toBe('MCP_BRIDGE_RATE_LIMITED')
        ->and($refusal->text)->toContain('Retry after');
});

it('counts the rate limit per actor, not globally', function () {
    $gate = app(AuthorizesTools::class);
    $definition = toolNamed('list_reports');

    $gate->check(callTool($definition, actor: new TokenUser(['reports:read'], 201)));
    $gate->check(callTool($definition, actor: new TokenUser(['reports:read'], 201)));
    $gate->check(callTool($definition, actor: new TokenUser(['reports:read'], 201)));

    expect($gate->check(callTool($definition, actor: new TokenUser(['reports:read'], 202))))->toBeNull();
});

describe('a rate limit that cannot be read', function () {
    it('refuses the tool rather than serving it with no limit', function () {
        config(['mcp-bridge.routes' => ['api.v1.reports.index' => ['rate_limit' => '1000/day']]]);

        $result = compileFixtures();
        $refusal = $result->refusals['route:api.v1.reports.index:GET'][0] ?? null;

        expect($result->names())->not->toContain('list_reports')
            ->and($refusal?->code)->toBe('MCP_BRIDGE_INVALID_RATE_LIMIT')
            ->and($refusal?->message)->toContain('[1000/day]')
            ->and($refusal?->message)->toContain('[routes.api.v1.reports.index.rate_limit]');
    });

    it('costs only the tools it would have limited', function () {
        $served = compileFixtures()->names();

        config(['mcp-bridge.sources.route.rate_limit' => '10/min']);

        $refused = array_keys(array_filter(
            compileFixtures()->refusals,
            fn (array $refusals): bool => $refusals[0]->code === 'MCP_BRIDGE_INVALID_RATE_LIMIT',
        ));

        // Every tool that was served, and nothing that was already refused
        // for another reason, such as not being opted in. list_reports keeps
        // its own attribute, which is readable.
        expect($refused)->toHaveCount(count($served) - 1)
            ->and(compileFixtures()->names())->toBe(['list_reports']);
    });

    it('treats a blank limit as none, as it always did', function () {
        config(['mcp-bridge.sources.route.rate_limit' => '']);

        expect(toolNamed('list_courses')->limits->rateLimit)->toBeNull();
    });

    it('refuses a call from a manifest built before the compiler refused it', function () {
        $stale = ToolDefinition::fromArray([
            ...toolNamed('list_reports')->toArray(),
            'limits' => ['timeout' => 30, 'rate_limit' => '1000/day'],
        ]);

        $refusal = app(AuthorizesTools::class)->check(callTool($stale, actor: new TokenUser(['reports:read'], 301)));

        expect($refusal?->errorCode)->toBe('MCP_BRIDGE_INVALID_RATE_LIMIT');
    });
});

describe('confirmation', function () {
    it('asks for confirmation before a destructive call, without erroring', function () {
        $result = app(AuthorizesTools::class)->check(callTool(toolNamed('purge_course')));

        expect($result)->not->toBeNull()
            ->and($result->isError)->toBeFalse()
            ->and($result->errorCode)->toBe('MCP_BRIDGE_CONFIRMATION_REQUIRED')
            ->and($result->text)->toContain('Permanently purge every archived course?')
            ->and($result->structured['confirmation']['handle'])->toBeString()
            // In the text too, or a client that reads only text is told to
            // send back a handle it was never shown.
            ->and($result->text)->toContain($result->structured['confirmation']['handle'])
            ->and($result->structured['confirmation']['expires_in'])->toBe(300);
    });

    it('lets the call through when the handle comes back', function () {
        $gate = app(AuthorizesTools::class);
        $definition = toolNamed('purge_course');

        $handle = $gate->check(callTool($definition))->structured['confirmation']['handle'];

        expect($gate->check(callTool($definition, confirm: $handle)))->toBeNull();
    });

    it('spends a handle, so a retry cannot repeat the call', function () {
        $gate = app(AuthorizesTools::class);
        $definition = toolNamed('purge_course');

        $handle = $gate->check(callTool($definition))->structured['confirmation']['handle'];

        expect($gate->check(callTool($definition, confirm: $handle)))->toBeNull();

        // The same handle, the same arguments, the same actor, inside the
        // lifetime: an agent retrying a dropped connection would otherwise
        // purge twice.
        $replay = $gate->check(callTool($definition, confirm: $handle));

        expect($replay->errorCode)->toBe('MCP_BRIDGE_CONFIRMATION_EXPIRED')
            ->and($replay->isError)->toBeTrue();
    });

    it('refuses to confirm at all when the cache cannot promise single use', function () {
        // Fails closed: unable to record that a handle was spent is unable to
        // stop it being spent twice.
        config(['cache.default' => 'null']);

        $gate = app(AuthorizesTools::class);
        $definition = toolNamed('purge_course');

        $handle = $gate->check(callTool($definition))->structured['confirmation']['handle'];

        expect($gate->check(callTool($definition, confirm: $handle))->errorCode)
            ->toBe('MCP_BRIDGE_CONFIRMATION_EXPIRED');
    });

    it('invalidates a handle when the arguments change', function () {
        $gate = app(AuthorizesTools::class);
        $definition = toolNamed('delete_course');

        $handle = $gate->check(callTool($definition, ['course' => 1]))
            ->structured['confirmation']['handle'];

        expect($gate->check(callTool($definition, ['course' => 1], confirm: $handle)))->toBeNull();

        $refusal = $gate->check(callTool($definition, ['course' => 2], confirm: $handle));

        expect($refusal->errorCode)->toBe('MCP_BRIDGE_CONFIRMATION_EXPIRED')
            ->and($refusal->isError)->toBeTrue();
    });

    it('rejects a handle issued for another tool or another actor', function () {
        $gate = app(AuthorizesTools::class);

        $handle = $gate->check(callTool(toolNamed('purge_course')))
            ->structured['confirmation']['handle'];

        expect($gate->check(callTool(toolNamed('delete_course'), ['course' => 1], confirm: $handle))->errorCode)
            ->toBe('MCP_BRIDGE_CONFIRMATION_EXPIRED')
            ->and($gate->check(callTool(toolNamed('purge_course'), actor: new TestUser(999), confirm: $handle))->errorCode)
            ->toBe('MCP_BRIDGE_CONFIRMATION_EXPIRED');
    });

    it('rejects a handle that has expired', function () {
        config(['mcp-bridge.security.confirm_handle_ttl' => '0s']);

        $gate = app(AuthorizesTools::class);
        $definition = toolNamed('purge_course');

        $handle = $gate->check(callTool($definition))->structured['confirmation']['handle'];

        expect($gate->check(callTool($definition, confirm: $handle))->errorCode)
            ->toBe('MCP_BRIDGE_CONFIRMATION_EXPIRED');
    });

    it('rejects a forged handle', function () {
        $refusal = app(AuthorizesTools::class)->check(
            callTool(toolNamed('purge_course'), confirm: 'not-a-real-handle'),
        );

        expect($refusal->errorCode)->toBe('MCP_BRIDGE_CONFIRMATION_EXPIRED');
    });

    it('advertises the confirm handle as an input so an agent can find it', function () {
        $input = toolNamed('purge_course')->input;

        expect($input->get('confirm'))->not->toBeNull()
            ->and($input->get('confirm')->hidden)->toBeFalse()
            // No wire, so it is never forwarded to the code behind the tool.
            ->and($input->get('confirm')->wire)->toBeNull()
            ->and(toolNamed('list_courses')->input->get('confirm'))->toBeNull();
    });

    it('reads a ttl in seconds, minutes or hours', function (string $ttl, int $expected) {
        config(['mcp-bridge.security.confirm_handle_ttl' => $ttl]);

        expect(app(ConfirmationHandles::class)->ttl())->toBe($expected);
    })->with([
        ['30s', 30],
        ['5m', 300],
        ['2h', 7200],
        ['nonsense', 300],
    ]);
});

describe('destructive deny', function () {
    it('does not expose a destructive tool at all', function () {
        config(['mcp-bridge.security.destructive' => 'deny']);

        $result = compileFixtures();

        // The strictest of the three values used to be the weakest: it fell
        // through the one test for 'confirm' and exposed the tool unguarded.
        expect($result->names())->toContain('list_courses')
            ->and($result->names())->not->toContain('delete_course');
    });

    it('still confirms rather than denies on the default setting', function () {
        expect(compileFixtures()->names())->toContain('delete_course');

        expect(app(AuthorizesTools::class)->check(callTool(toolNamed('delete_course'), ['course' => 1]))
            ->structured['confirmation']['required'])->toBeTrue();
    });
});

describe('who a call runs as', function () {
    beforeEach(function () {
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('email')->nullable();
        });

        TestUser::query()->insert([['id' => 42, 'email' => 'automation@example.com']]);
    });

    it('runs as the token holder by default', function () {
        expect(app(ActorResolver::class)->resolve(new TestUser(7))?->getAuthIdentifier())->toBe(7);
    });

    it('runs as the configured service account instead, found by id or by email', function () {
        config(['mcp-bridge.security.auth' => 'service-account']);

        config(['mcp-bridge.security.service_account' => 42]);
        expect(app(ActorResolver::class)->resolve(new TestUser(7))?->getAuthIdentifier())->toBe(42);

        config(['mcp-bridge.security.service_account' => 'automation@example.com']);
        expect(app(ActorResolver::class)->resolve(new TestUser(7))?->getAuthIdentifier())->toBe(42);
    });

    it('refuses rather than quietly running an automation as a person', function () {
        config([
            'mcp-bridge.security.auth' => 'service-account',
            'mcp-bridge.security.service_account' => 'nobody@example.com',
        ]);

        $resolver = app(ActorResolver::class);

        expect($resolver->resolve(new TestUser(7)))->toBeNull()
            ->and($resolver->problem())->toContain('no such user exists');
    });

    it('lists a tool against the actor the call will run as', function () {
        config([
            'mcp-bridge.security.auth' => 'service-account',
            'mcp-bridge.security.service_account' => 42,
        ]);

        // Listing and calling must answer about the same person, or a tool is
        // offered to a caller the gate then refuses.
        $definition = toolNamed('purge_course');
        $tool = app(GeneratedTool::class, ['definition' => $definition]);

        $listedFor = $tool->shouldRegister(new McpRequest([]));

        expect($listedFor)->toBe(app(AuthorizesTools::class)->visible(
            $definition,
            app(ActorResolver::class)->resolve(new TestUser(7)),
        ));
    });

    it('says so when the account is named nowhere', function () {
        config(['mcp-bridge.security.auth' => 'service-account']);

        expect(app(ActorResolver::class)->problem())->toContain('names nobody');
    });

    describe('over stdio', function () {
        it('runs as the configured local actor when nothing authenticated the caller', function () {
            ServerKeyResolver::serving('default');
            config(['mcp-bridge.security.local_actor' => 'automation@example.com']);

            // A subprocess carries no token, so under passthrough there was
            // nobody to pass through and every local call was refused.
            expect(app(ActorResolver::class)->resolve(null)?->getAuthIdentifier())->toBe(42);
        });

        it('refuses when no local actor is named, and says which key to set', function () {
            ServerKeyResolver::serving('default');

            $resolver = app(ActorResolver::class);

            expect($resolver->resolve(null))->toBeNull()
                ->and($resolver->problem())->toContain('security.local_actor');
        });

        it('lets an authenticated caller win over the local actor', function () {
            ServerKeyResolver::serving('default');
            config(['mcp-bridge.security.local_actor' => 42]);

            expect(app(ActorResolver::class)->resolve(new TestUser(7))?->getAuthIdentifier())->toBe(7);
        });

        it('finds the actor past a guard with no provider, and through the default provider when every guard declines', function () {
            ServerKeyResolver::serving('default');
            config(['mcp-bridge.security.local_actor' => 'automation@example.com']);

            // A request guard built with no provider answers getProvider()
            // with null, which is what Sanctum's does; listed first, it must
            // not end the lookup.
            Auth::extend('nil', fn () => new RequestGuard(fn () => null, request(), null));
            config(['auth.guards.nil' => ['driver' => 'nil']]);
            config(['mcp-bridge.security.guards' => ['nil', 'mcp-bridge']]);

            expect(app(ActorResolver::class)->resolve(null)?->getAuthIdentifier())->toBe(42);

            // A guard the configuration names and nothing defines is skipped,
            // and with nothing left the application's default provider answers.
            config(['mcp-bridge.security.guards' => ['nil', 'nowhere']]);

            expect(app(ActorResolver::class)->resolve(null)?->getAuthIdentifier())->toBe(42);
        });

        it('never applies the local actor over http', function () {
            config(['mcp-bridge.security.local_actor' => 42]);

            $resolver = app(ActorResolver::class);

            // Read only when serving locally, so it cannot widen an HTTP
            // surface: an unauthenticated HTTP caller is still nobody.
            expect($resolver->resolve(null))->toBeNull()
                ->and($resolver->problem())->toBeNull();
        });
    });
});

describe('require_policy', function () {
    beforeEach(function () {
        // Enrolment has no policy, unlike Course. Registered here rather than
        // in the shared fixtures, so no other test's surface moves.
        config(['mcp-bridge.mode' => 'opt-out']);

        Route::patch('api/v1/enrolments/{enrolment}', [EnrolmentController::class, 'update'])
            ->name('enrolments.update');
    });

    it('refuses a write whose model has no policy when asked to', function () {
        config(['mcp-bridge.security.require_policy' => true]);

        $result = compileFixtures();

        expect($result->names())->not->toContain('update_enrolment')
            // Course has one, so its write survives the same setting.
            ->and($result->names())->toContain('delete_course');
    });

    it('is off by default, because a route without a policy is how Laravel ships', function () {
        expect(compileFixtures()->names())->toContain('update_enrolment', 'delete_course');
    });

    it('never refuses a read, which has nothing to authorise against a policy', function () {
        config(['mcp-bridge.security.require_policy' => true]);

        Route::get('api/v1/enrolments/{enrolment}', [EnrolmentController::class, 'show'])
            ->name('enrolments.show');

        expect(compileFixtures()->names())->toContain('get_enrolment');
    });
});

describe('a hidden field is not the agent to set', function () {
    it('drops it on an action, which has no rules to drop it for us', function () {
        config([
            'mcp-bridge.sources.action.enabled' => true,
            'mcp-bridge.sources.action.paths' => [__DIR__.'/../Fixtures/Actions'],
            'mcp-bridge.security.fill_from_auth' => ['reason'],
        ]);

        $definition = null;

        foreach (app(ManifestCompiler::class)->compile()->definitions as $candidate) {
            if (str_contains($candidate->name, 'archive')) {
                $definition = $candidate;
            }
        }

        expect($definition)->not->toBeNull()
            ->and($definition->input->get('reason')?->hidden)->toBeTrue();

        // Hiding it from the schema is the courtesy. This is the enforcement:
        // an agent that sends it anyway, having guessed or read the REST docs,
        // has its value replaced by the actor's, the way a route or a model
        // write fills the field, and never forwarded.
        $call = callTool($definition, ['reason' => 'sent anyway', 'course' => 1]);

        expect($call->visibleArguments()['reason'])->toBe($call->actor->getAuthIdentifier())
            ->and($call->arguments['reason'])->toBe('sent anyway');
    });
});
