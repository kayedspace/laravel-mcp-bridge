<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Attributes\McpRateLimit;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Output\OutputShaper;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;

beforeEach(fn () => config(['mcp-bridge.mode' => 'opt-in']));

it('assigns a group from the class attribute', function () {
    expect(toolNamed('list_reports')->groups)->toBe(['reports']);
});

it('lets a safety annotation override what the http method implied', function () {
    // A POST is neither read-only nor destructive by inference, and
    // #[IsDestructive] says otherwise.
    $purge = toolNamed('purge_course');

    expect($purge->safety->destructive)->toBeTrue()
        ->and($purge->safety->readOnly)->toBeFalse()
        ->and($purge->origin['safety'])->toBe('attribute');
});

it('reads laravel mcp safety annotations off a controller method', function () {
    // The annotation declares TARGET_CLASS, so it is read by arguments rather
    // than instantiated, which is what lets it sit on a method at all.
    expect(toolNamed('list_reports')->safety->readOnly)->toBeTrue();
});

it('collects scopes and abilities into the tool authority', function () {
    $authority = toolNamed('list_reports')->authority;

    expect($authority->scopes)->toBe(['reports:read'])
        ->and($authority->abilities)->toBe(['run-reports'])
        ->and($authority->isDeclared())->toBeTrue();
});

it('records a per tool rate limit and timeout', function () {
    $limits = toolNamed('list_reports')->limits;

    expect($limits->rateLimit)->toBe('2/minute')
        ->and($limits->timeout)->toBe(120);
});

it('applies McpParam over the inferred schema', function () {
    $input = toolNamed('list_reports')->input;

    expect($input->get('from')->description)->toBe('Start date, inclusive.')
        ->and($input->get('from')->examples)->toBe(['2026-01-01'])
        ->and($input->get('from')->origin)->toContain('attribute:McpParam')
        ->and($input->get('currency')->enum)->toBe(['usd', 'eur'])
        ->and($input->get('currency')->type)->toBe(FieldType::String);
});

it('hides a field McpParam marks hidden', function () {
    $input = toolNamed('list_reports')->input;

    expect($input->get('internal_flag')->hidden)->toBeTrue();
});

it('folds examples into the description an agent reads', function () {
    $description = toolNamed('list_reports')->description;

    expect($description)->toContain('Example, since the start of the year: {"from":"2026-01-01"}')
        ->and($description)->toContain('Requires the scope reports:read.');
});

it('marks a deprecated tool without hiding it', function () {
    $legacy = toolNamed('legacy_course');

    expect($legacy->deprecated->message)->toBe('Use list_courses instead.')
        ->and($legacy->deprecated->until)->toBe('2.0')
        ->and($legacy->description)->toContain('Deprecated. Use list_courses instead.')
        ->and($legacy->description)->toContain('Scheduled for removal in 2.0.');
});

it('builds a schema from the form request McpInput names', function () {
    // The action type-hints a plain Request, so without the attribute there
    // would be no rules to read at all.
    $input = toolNamed('inline_course')->input;

    expect($input->get('title')->required)->toBeTrue()
        ->and($input->get('title')->max)->toBe(255)
        ->and($input->get('status')->enum)->toBe(['draft', 'published']);
});

it('keeps only the fields McpOutput asked for, and caps the list', function () {
    $definition = toolNamed('list_reports');

    expect($definition->output->fields)->toBe(['id', 'total'])
        ->and($definition->output->maxItems)->toBe(2);

    $result = app(ExecutesTools::class)->execute(callTool($definition, [], new TokenUser(['*'])));
    $shaped = app(OutputShaper::class)->shape($result, $definition);

    expect($shaped->structured['data'])->toHaveCount(2)
        ->and($shaped->structured['data'][0])->toBe(['id' => 1, 'total' => 100]);
});

describe('configuration that overrides code', function () {
    it('sends a tool to the server its group claims', function () {
        config(['mcp-bridge.servers.ops.groups' => ['reports']]);

        // The route is a route, so by source it belongs on the default
        // server; its group moves that one tool and nothing else.
        expect(toolNamed('list_reports')->server)->toBe('ops')
            ->and(toolNamed('list_courses')->server)->toBe('default');
    });

    it('renames a tool and limits it from config, without touching the code', function () {
        config(['mcp-bridge.routes' => [
            'api.v1.courses.index' => ['name' => 'browse_catalogue', 'rate_limit' => '3/minute'],
        ]]);

        $result = compileFixtures();

        expect($result->names())->toContain('browse_catalogue')
            ->and($result->names())->not->toContain('list_courses')
            ->and(toolNamed('browse_catalogue', $result)->limits->rateLimit)->toBe('3/minute');
    });

    it('names tools in the style the application asked for', function () {
        config(['mcp-bridge.naming.style' => 'kebab']);
        expect(compileFixtures()->names())->toContain('list-courses');

        config(['mcp-bridge.naming.style' => 'camel']);
        expect(compileFixtures()->names())->toContain('listCourses');
    });
});

it('places a command by its group when a server claims it, whatever the source server says', function () {
    config(['mcp-bridge.servers.default.groups' => ['reporting']]);

    expect(toolNamed('get_stats', compileCommandFixtures())->server)->toBe('default')
        ->and(toolNamed('recalculate_stats', compileCommandFixtures())->server)->toBe('ops');
});

it('sanitises a configured name like one the code suggested', function () {
    config(['mcp-bridge.routes' => ['api.v1.courses.index' => ['name' => 'Browse Catalogue!']]]);

    expect(compileFixtures()->names())->toContain('browse_catalogue');
});

it('writes a rate limit per second when asked', function () {
    expect((new McpRateLimit(5, perSeconds: 10))->toLimit())->toBe('5/10seconds')
        ->and((new McpRateLimit(5, perMinutes: 2))->toLimit())->toBe('5/2minutes');
});
