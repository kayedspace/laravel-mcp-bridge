<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Execution\ActionExecutor;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Schema\FormRequestReader;
use KayedSpace\McpBridge\Tests\Fixtures\CourseData;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use Spatie\LaravelData\Data;

/**
 * spatie/laravel-data objects as a rules source. The package is optional,
 * so every test here steps aside when it is not installed, and the
 * `integrations: absent` row of CI runs exactly that.
 */
function compileDataActions(): CompileResult
{
    config()->set('mcp-bridge.sources.action.enabled', true);
    config()->set('mcp-bridge.sources.action.paths', [__DIR__.'/../Fixtures/Actions']);

    return app(ManifestCompiler::class)->compile();
}

function importCourse(array $arguments): SourceResult
{
    return app(ActionExecutor::class)->execute(callTool(
        toolNamed('import_course', compileDataActions()),
        $arguments,
        new TokenUser(['courses:write'], 9),
    ));
}

beforeEach(function (): void {
    app(Gate::class)->define('publish-courses', fn (): bool => true);
});

$installed = class_exists(Data::class);

it('reads a data class as a rules source', function (): void {
    $rules = app(FormRequestReader::class)->rulesFor(CourseData::class);

    expect($rules)->toHaveKeys(['title', 'status', 'summary'])
        ->and($rules['title'])->toContain('required');
})->skip(! $installed, 'spatie/laravel-data is not installed');

it('flattens a data parameter into the tool inputs', function (): void {
    $input = toolNamed('import_course', compileDataActions())->input;

    // The agent sees the object's fields, not a `data` whose shape it would
    // have to guess, and the fields carry the object's own rules.
    expect($input->get('data'))->toBeNull()
        ->and($input->get('title')?->required)->toBeTrue()
        ->and($input->get('status')?->enum)->toContain('draft', 'published')
        ->and($input->get('summary')?->max)->toBe(120)
        ->and($input->get('summary')?->nullable)->toBeTrue();
})->skip(! $installed, 'spatie/laravel-data is not installed');

it('builds the data object and hands it to the action', function (): void {
    $result = importCourse(['title' => 'Immunology', 'status' => 'draft']);

    expect($result->successful())->toBeTrue()
        ->and($result->payload)->toMatchArray(['title' => 'Immunology', 'status' => 'draft', 'summary' => null]);
})->skip(! $installed, 'spatie/laravel-data is not installed');

it('validates with the data object own rules', function (): void {
    $result = importCourse(['status' => 'draft']);

    expect($result->status)->toBe(422)
        ->and($result->payload['errors'])->toHaveKey('title');
})->skip(! $installed, 'spatie/laravel-data is not installed');
