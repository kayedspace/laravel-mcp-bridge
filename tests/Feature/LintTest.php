<?php

declare(strict_types=1);

use Dedoc\Scramble\Attributes\Parameter as ScrambleParameter;
use KayedSpace\McpBridge\Lint\Finding;
use KayedSpace\McpBridge\Lint\Linter;

/**
 * @param  list<Finding>  $findings
 * @return list<Finding>
 */
function findingsOf(array $findings, string $code): array
{
    return array_values(array_filter(
        $findings,
        fn (Finding $finding): bool => $finding->code === $code,
    ));
}

/** @return list<Finding> */
function lint(bool $all = false, ?string $only = null): array
{
    return app(Linter::class)->lint($all, $only);
}

it('says nothing about a healthy application', function (): void {
    defineApiRoutes();

    expect(findingsOf(lint(), 'MCP_BRIDGE_LINT_EMPTY'))->toBe([]);
});

it('warns when an application exposes nothing at all', function (): void {
    $findings = findingsOf(lint(), 'MCP_BRIDGE_LINT_EMPTY');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->level)->toBe(Finding::WARNING)
        ->and($findings[0]->hint)->toContain('#[McpTool]');
});

it('reports a tool with no description, because an agent reads it', function (): void {
    defineApiRoutes();

    $subjects = array_map(
        fn (Finding $finding): string => $finding->subject,
        findingsOf(lint(), 'MCP_BRIDGE_LINT_NO_DESCRIPTION'),
    );

    expect($subjects)->toContain('route:api.v1.courses.show:GET');
});

it('names tools the way the compiler does, so a prefixed model tool is no collision', function (): void {
    defineApiRoutes();
    config()->set('mcp-bridge.sources.model.enabled', true);
    config()->set('mcp-bridge.sources.model.paths', [__DIR__.'/../Fixtures']);
    config()->set('mcp-bridge.sources.model.operations', ['get']);

    expect(findingsOf(lint(), 'MCP_BRIDGE_LINT_COLLISION'))->toBe([]);

    config()->set('mcp-bridge.naming.source_prefixes', []);

    $subjects = array_map(fn (Finding $finding): string => $finding->subject, findingsOf(lint(), 'MCP_BRIDGE_LINT_COLLISION'));

    expect($subjects)->toContain('model:KayedSpace\\McpBridge\\Tests\\Fixtures\\Course:get');
});

it('notes a timeout on a source that cannot enforce it', function (): void {
    defineApiRoutes();

    $subjects = array_map(
        fn (Finding $finding): string => $finding->subject,
        findingsOf(lint(), 'MCP_BRIDGE_LINT_TIMEOUT_ADVISORY'),
    );

    expect($subjects)->toBe(['route:api.v1.reports.index:GET']);
});

it('warns about a model write that validates nothing beyond the database', function (): void {
    config()->set('mcp-bridge.sources.model.enabled', true);
    config()->set('mcp-bridge.sources.model.paths', [__DIR__.'/../Fixtures']);
    config()->set('mcp-bridge.sources.model.operations', ['list', 'create', 'update']);
    config()->set('mcp-bridge.sources.model.require_policy', false);

    $subjects = array_map(
        fn (Finding $finding): string => $finding->subject,
        findingsOf(lint(only: 'model'), 'MCP_BRIDGE_LINT_NO_RULES'),
    );

    expect($subjects)->toContain('model:KayedSpace\\McpBridge\\Tests\\Fixtures\\Course:create')
        ->and($subjects)->toContain('model:KayedSpace\\McpBridge\\Tests\\Fixtures\\Course:update')
        ->and($subjects)->not->toContain('model:KayedSpace\\McpBridge\\Tests\\Fixtures\\Course:list');
});

it('stays quiet about a choice unless asked', function (): void {
    defineApiRoutes();

    expect(findingsOf(lint(), 'MCP_BRIDGE_NOT_EXPOSED'))->toBe([])
        ->and(findingsOf(lint(all: true), 'MCP_BRIDGE_NOT_EXPOSED'))->not->toBe([]);
});

describe('commands', function (): void {
    it('errors on a command that opted in but was refused', function (): void {
        defineCommands();

        $findings = findingsOf(lint(only: 'command'), 'MCP_BRIDGE_SOURCE_REFUSED');

        expect($findings)->not->toBe([])
            ->and($findings[0]->level)->toBe(Finding::ERROR)
            ->and(array_map(fn (Finding $finding): string => $finding->subject, $findings))
            ->toContain('command:stats:unprotected');
    });

    it('errors on a command that would stop to ask a question', function (): void {
        defineCommands();

        $findings = findingsOf(lint(only: 'command'), 'MCP_BRIDGE_LINT_INTERACTIVE');

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->subject)->toBe('command:stats:prompt')
            ->and($findings[0]->message)->toContain('ask() at')
            ->and($findings[0]->hint)->toContain('#[McpIgnore]');
    });

    it('leaves a command that never prompts alone', function (): void {
        defineCommands();

        $subjects = array_map(
            fn (Finding $finding): string => $finding->subject,
            findingsOf(lint(only: 'command'), 'MCP_BRIDGE_LINT_INTERACTIVE'),
        );

        expect($subjects)->not->toContain('command:stats:recalculate');
    });
});

describe('documentation divergence', function (): void {
    it('reports a field the docs and the MCP attribute describe differently', function (): void {
        defineDocumentedRoutes();

        $findings = findingsOf(lint(), 'MCP_BRIDGE_LINT_DIVERGENCE');

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->subject)->toBe('route:api.v1.documented.store:POST')
            ->and($findings[0]->message)
            ->toContain('Where the course came from')
            ->toContain('Our wording for the source')
            ->and($findings[0]->hint)->toContain('The MCP attribute wins for the agent');
    });

    it('notes a documented parameter the agent never gets to see', function (): void {
        defineDocumentedRoutes();

        $findings = findingsOf(lint(all: true), 'MCP_BRIDGE_LINT_HIDDEN_DOC');

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->level)->toBe(Finding::NOTE)
            ->and($findings[0]->message)->toContain('internal_flag');
    });
})->skip(
    fn (): bool => ! class_exists(ScrambleParameter::class),
    'dedoc/scramble is not installed.',
);

it('fails the command when something is broken, and passes when nothing is', function (): void {
    defineApiRoutes();

    $this->artisan('mcp-bridge:lint')->assertSuccessful();

    defineCommands();

    $this->artisan('mcp-bridge:lint --source=command')->assertFailed();
});

it('prints findings as json for CI', function (): void {
    defineCommands();

    $this->artisan('mcp-bridge:lint --source=command --json')
        ->expectsOutputToContain('MCP_BRIDGE_LINT_INTERACTIVE')
        ->assertFailed();
});

it('does not fail the lint over a framework command nobody opted in', function (): void {
    config()->set('mcp-bridge.sources.command.enabled', true);

    $errors = array_filter(lint(), fn ($finding): bool => $finding->level === 'error');
    $messages = array_map(fn ($finding): string => $finding->message, $errors);

    // migrate is on the built-in deny list and carries no #[McpTool]. Nobody
    // asked for it, so it is not a mistake anyone has to fix.
    expect(implode(' ', $messages))->not->toContain('migrate');
});

it('warns about an attribute nothing on that source reads', function (): void {
    config()->set('mcp-bridge.sources.action.enabled', true);
    config()->set('mcp-bridge.sources.action.paths', [__DIR__.'/../Fixtures/Actions']);

    $messages = array_map(
        fn (Finding $finding): string => $finding->message,
        array_values(array_filter(
            findingsOf(lint(), 'MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE'),
            fn (Finding $finding): bool => str_contains($finding->subject, 'QueuedLookalike'),
        )),
    );

    expect($messages)->toHaveCount(2)
        ->and(implode(' ', $messages))->toContain('#[McpQueue] is read on job tools')
        ->and(implode(' ', $messages))->toContain("#[McpParam('nothing_here')] names a field this tool does not have");
});
