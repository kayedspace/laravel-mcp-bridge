<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use KayedSpace\McpBridge\Contracts\AuthorizesTools;
use KayedSpace\McpBridge\Discovery\CommandFilter;
use KayedSpace\McpBridge\Execution\CommandExecutor;
use KayedSpace\McpBridge\McpBridgeManager;
use KayedSpace\McpBridge\Server\GeneratedTool;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/** An operator with everything a command tool asks for. */
function operator(): TokenUser
{
    return new TokenUser(['ops:write', 'ops:read'], 7);
}

beforeEach(function (): void {
    app(Gate::class)->define('run-reports', fn (): bool => true);
});

describe('discovery', function (): void {
    it('exposes a command that opted in, and names it after its signature', function (): void {
        // stats:show reads as get_stats, because `show` is a known verb and
        // the namespace is the resource.
        expect(compileCommandFixtures()->names())->toContain('recalculate_stats', 'get_stats');
    });

    it('never exposes a command that did not opt in, whatever the mode', function (): void {
        config()->set('mcp-bridge.mode', 'opt-out');

        $result = compileCommandFixtures();

        expect($result->refusals['command:route:list'][0]->code ?? null)->toBe('MCP_BRIDGE_NOT_EXPOSED')
            ->and($result->refusals['command:route:list'][0]->message)->toContain('Add #[McpTool]');
    });

    it('refuses a command that declares no authority of its own', function (): void {
        $result = compileCommandFixtures();

        expect($result->names())->not->toContain('unprotected_stats')
            ->and($result->refusals['command:stats:unprotected'][0]->message)
            ->toContain('needs both #[McpScope] and #[McpAbility]');
    });

    it('refuses a denied command even though it opted in', function (): void {
        config()->set('mcp-bridge.sources.command.deny', ['stats:recalculate']);

        $result = compileCommandFixtures();

        expect($result->names())->not->toContain('recalculate_stats')
            ->and($result->refusals['command:stats:recalculate'][0]->message)
            ->toContain('is on the deny list');
    });

    it('lets allow_denied lift a denial, one command at a time', function (): void {
        config()->set('mcp-bridge.sources.command.deny', ['stats:*']);
        config()->set('mcp-bridge.sources.command.allow_denied', ['stats:recalculate']);

        $result = compileCommandFixtures();

        expect($result->names())->toContain('recalculate_stats')
            ->and($result->names())->not->toContain('get_stats');
    });

    it('refuses a command outside the allowed namespaces', function (): void {
        config()->set('mcp-bridge.sources.command.namespaces', ['reports']);

        $result = compileCommandFixtures();

        expect($result->names())->not->toContain('recalculate_stats')
            ->and($result->refusals['command:stats:recalculate'][0]->message)
            ->toContain('outside the namespaces');
    });

    it('lets no command opt in from an empty namespace list, and any from a wildcard', function (): void {
        config()->set('mcp-bridge.sources.command.namespaces', []);

        expect(compileCommandFixtures()->names())->not->toContain('recalculate_stats');

        config()->set('mcp-bridge.sources.command.namespaces', ['*']);

        expect(compileCommandFixtures()->names())->toContain('recalculate_stats');
    });

    it('keeps the built-in deny list under the configured one', function (): void {
        config()->set('mcp-bridge.sources.command.deny', []);

        $filter = app(CommandFilter::class);

        expect($filter->isDenied('db:wipe'))->toBeTrue()
            ->and($filter->isDenied('migrate:fresh'))->toBeTrue()
            ->and($filter->isDenied('mcp-bridge:cache'))->toBeTrue()
            ->and($filter->isDenied('stats:recalculate'))->toBeFalse();
    });

    it('sends command tools to their own server', function (): void {
        expect(toolNamed('recalculate_stats', compileCommandFixtures())->server)->toBe('ops');
    });
});

describe('the schema', function (): void {
    it('reads arguments, options, flags and lists from the signature', function (): void {
        $input = toolNamed('recalculate_stats', compileCommandFixtures())->input;

        expect($input->get('course')?->required)->toBeTrue()
            ->and($input->get('course')?->description)->toBe('The course to recalculate')
            ->and($input->get('course')?->wire?->in->value)->toBe('command-argument')
            ->and($input->get('from')?->wire?->in->value)->toBe('command-option')
            ->and($input->get('from')?->description)->toBe('Start date')
            ->and($input->get('force')?->type->value)->toBe('boolean')
            ->and($input->get('force')?->wire?->flag)->toBeTrue()
            ->and($input->get('tag')?->type->value)->toBe('array');
    });

    it('leaves the global console options out', function (): void {
        $input = toolNamed('recalculate_stats', compileCommandFixtures())->input;

        expect($input->get('verbose'))->toBeNull()
            ->and($input->get('no_interaction'))->toBeNull()
            ->and($input->get('env'))->toBeNull();
    });
});

describe('safety', function (): void {
    it('treats a command as destructive until it says otherwise', function (): void {
        $result = compileCommandFixtures();

        expect(toolNamed('recalculate_stats', $result)->safety->destructive)->toBeTrue()
            ->and(toolNamed('recalculate_stats', $result)->authority->confirm)->toBeTrue()
            ->and(toolNamed('get_stats', $result)->safety->readOnly)->toBeTrue()
            ->and(toolNamed('get_stats', $result)->authority->confirm)->toBeFalse();
    });

    it('asks for confirmation before running one', function (): void {
        $definition = toolNamed('recalculate_stats', compileCommandFixtures());

        $refusal = app(AuthorizesTools::class)->check(
            callTool($definition, ['course' => '7'], operator()),
        );

        expect($refusal?->errorCode)->toBe('MCP_BRIDGE_CONFIRMATION_REQUIRED');
    });
});

describe('execution', function (): void {
    it('runs the command and gives back what it printed', function (): void {
        $definition = toolNamed('recalculate_stats', compileCommandFixtures());

        $result = app(CommandExecutor::class)->execute(callTool($definition, [
            'course' => '7',
            'from' => '2026-01-01',
            'force' => true,
            'tag' => ['alpha', 'beta'],
        ], operator()));

        expect($result->status)->toBe(200)
            ->and($result->meta['exit_code'])->toBe(0)
            ->and($result->payload['output'])
            ->toContain('course=7')
            ->toContain('from=2026-01-01')
            ->toContain('force=yes')
            ->toContain('tags=alpha,beta');
    });

    it('runs as the agent, not as nobody', function (): void {
        $definition = toolNamed('recalculate_stats', compileCommandFixtures());

        $result = app(CommandExecutor::class)->execute(
            callTool($definition, ['course' => '7'], operator()),
        );

        expect($result->payload['output'])->toContain('actor=7')
            // The guard is left as it was found.
            ->and(app(McpBridgeManager::class)->actor())->toBeNull()
            ->and(auth()->user())->toBeNull();
    });

    it('leaves a false flag out rather than passing it as a value', function (): void {
        $definition = toolNamed('recalculate_stats', compileCommandFixtures());

        $result = app(CommandExecutor::class)->execute(callTool($definition, [
            'course' => '7',
            'force' => false,
        ], operator()));

        expect($result->payload['output'])->toContain('force=no');
    });

    it('maps a non-zero exit to an actionable error with the output tail', function (): void {
        $definition = toolNamed('fail_stats', compileCommandFixtures());

        $result = app(CommandExecutor::class)->execute(callTool($definition, actor: operator()));

        expect($result->successful())->toBeFalse()
            ->and($result->meta['code'])->toBe('MCP_BRIDGE_COMMAND_FAILED')
            ->and($result->meta['exit_code'])->toBe(1)
            ->and($result->payload['message'])
            ->toContain('exited with code 1')
            ->toContain('the warehouse is unreachable');
    });
});

describe('progress while it runs', function (): void {
    beforeEach(function (): void {
        // Read at compile time: without it the first call is a confirmation
        // preview rather than the run.
        config()->set('mcp-bridge.security.destructive', 'allow');

        auth()->guard()->setUser(operator());
    });

    it('streams each line as a progress notification when the client asked for progress', function (): void {
        $tool = app(GeneratedTool::class, ['definition' => toolNamed('recalculate_stats', compileCommandFixtures())]);

        $response = $tool->handle(new McpRequest(['course' => 7], ['progressToken' => 'p1']));

        expect($response)->toBeInstanceOf(Generator::class);

        $notifications = [];
        $final = null;

        foreach ($response as $item) {
            if ($item instanceof Response && $item->isNotification()) {
                $notifications[] = $item->content()->toArray();

                continue;
            }

            $final = $item;
        }

        // One notification per printed line, in order, numbered, carrying
        // the client's token; then the result the call would have had anyway.
        expect(count($notifications))->toBeGreaterThanOrEqual(5)
            ->and($notifications[0]['method'])->toBe('notifications/progress')
            ->and($notifications[0]['params'])->toMatchArray(['progressToken' => 'p1', 'progress' => 1, 'message' => 'course=7'])
            ->and($notifications[4]['params']['progress'])->toBe(5)
            ->and($notifications[4]['params']['message'])->toBe('actor=7')
            ->and($final)->toBeInstanceOf(ResponseFactory::class)
            ->and($final->getStructuredContent()['data']['output'])->toContain('actor=7');
    });

    it('answers in one piece when the client asked for no progress', function (): void {
        $tool = app(GeneratedTool::class, ['definition' => toolNamed('recalculate_stats', compileCommandFixtures())]);

        expect($tool->handle(new McpRequest(['course' => 7])))->toBeInstanceOf(ResponseFactory::class);
    });

    it('answers in one piece when streaming is switched off', function (): void {
        config()->set('mcp-bridge.sources.command.stream_output', false);

        $tool = app(GeneratedTool::class, ['definition' => toolNamed('recalculate_stats', compileCommandFixtures())]);

        expect($tool->handle(new McpRequest(['course' => 7], ['progressToken' => 'p1'])))
            ->toBeInstanceOf(ResponseFactory::class);
    });
});
