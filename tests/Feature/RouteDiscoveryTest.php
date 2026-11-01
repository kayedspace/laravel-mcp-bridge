<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Tests\Fixtures\CourseController;

it('exposes only opted in routes in opt-in mode', function () {
    config(['mcp-bridge.mode' => 'opt-in']);

    $names = compileFixtures()->names();

    expect($names)->toContain('list_courses', 'create_course', 'get_course', 'delete_course', 'publish_course')
        ->and($names)->not->toContain('export_course', 'internal_course', 'html_course');
});

it('names nested resource routes after both resources', function () {
    config(['mcp-bridge.mode' => 'opt-in']);

    $names = compileFixtures()->names();

    expect($names)->toContain('list_course_lessons', 'get_course_lesson');
});

it('never exposes an ignored route, whatever the mode', function (string $mode) {
    config(['mcp-bridge.mode' => $mode]);

    $result = compileFixtures();

    expect($result->names())->not->toContain('export_course')
        ->and($result->refusals['route:api.v1.courses.export:GET'][0]->code)->toBe('MCP_BRIDGE_IGNORED');
})->with(['opt-in', 'opt-out', 'read-only']);

it('explains why a route was not exposed', function () {
    config(['mcp-bridge.mode' => 'opt-in']);

    $refusals = compileFixtures()->refusals;

    expect($refusals)->toHaveKey('route:api.v1.courses.internal:GET')
        ->and($refusals['route:api.v1.courses.internal:GET'][0]->code)->toBe('MCP_BRIDGE_NOT_EXPOSED')
        ->and($refusals['route:api.v1.courses.internal:GET'][0]->message)->toContain('#[McpTool]');
});

it('exposes reads without an attribute in read-only mode, but not writes', function () {
    config(['mcp-bridge.mode' => 'read-only']);

    $names = compileFixtures()->names();

    // internal is a GET with no attribute, so read-only mode exposes it. A
    // non-CRUD action names itself, the same way publish_course does.
    expect($names)->toContain('internal_course')
        // store has an attribute, so it stays exposed even in read-only mode.
        ->and($names)->toContain('create_course');
});

it('exposes everything but ignores in opt-out mode', function () {
    config(['mcp-bridge.mode' => 'opt-out']);

    $names = compileFixtures()->names();

    expect($names)->toContain('internal_course', 'html_course', 'create_course')
        ->and($names)->not->toContain('export_course');
});

it('infers safety from the http method', function () {
    config(['mcp-bridge.mode' => 'opt-in']);

    $result = compileFixtures();

    expect(toolNamed('list_courses', $result)->safety->readOnly)->toBeTrue()
        ->and(toolNamed('delete_course', $result)->safety->destructive)->toBeTrue()
        ->and(toolNamed('create_course', $result)->safety->readOnly)->toBeFalse()
        ->and(toolNamed('create_course', $result)->safety->destructive)->toBeFalse();
});

it('requires confirmation for destructive tools by default', function () {
    config(['mcp-bridge.mode' => 'opt-in']);

    $result = compileFixtures();

    expect(toolNamed('delete_course', $result)->authority->confirm)->toBeTrue()
        ->and(toolNamed('list_courses', $result)->authority->confirm)->toBeFalse();
});

it('respects an explicit name from the attribute', function () {
    config(['mcp-bridge.mode' => 'opt-in']);

    // The route is courses.publish, and the attribute names it explicitly.
    expect(toolNamed('publish_course')->candidateId)->toBe('route:api.v1.courses.publish:POST');
});

it('compiles the same manifest twice in a row', function () {
    config(['mcp-bridge.mode' => 'opt-out']);

    defineApiRoutes();

    $first = app(ManifestCompiler::class)->compile();
    $second = app(ManifestCompiler::class)->compile();

    expect($second->names())->toBe($first->names());
});

it('ignores routes outside the configured prefixes', function () {
    config(['mcp-bridge.mode' => 'opt-out', 'mcp-bridge.discovery.prefixes' => ['admin']]);

    expect(compileFixtures()->names())->toBe([]);
});

it('discovers nothing from an empty allowlist, and everything from a wildcard', function () {
    config(['mcp-bridge.mode' => 'opt-out']);

    // An empty list that quietly meant everything was a setting that
    // exposed the most when it said the least. Each allowlist now reads
    // the same way: * is everything, [] is nothing.
    config(['mcp-bridge.discovery.prefixes' => []]);
    expect(compileFixtures()->names())->toBe([]);

    config(['mcp-bridge.discovery.prefixes' => ['*'], 'mcp-bridge.discovery.include' => []]);
    expect(compileFixtures()->names())->toBe([]);

    config(['mcp-bridge.discovery.include' => ['*'], 'mcp-bridge.discovery.domains' => []]);
    expect(compileFixtures()->names())->toBe([]);

    config(['mcp-bridge.discovery.domains' => ['*'], 'mcp-bridge.discovery.methods' => []]);
    expect(compileFixtures()->names())->toBe([]);

    // A wildcard prefix declares no prefix to strip, so the derived names
    // keep the `api` segment the configured prefix would have removed.
    config(['mcp-bridge.discovery.methods' => ['*']]);
    expect(compileFixtures()->names())->toContain('list_api_courses', 'create_api_course');

    config(['mcp-bridge.discovery.prefixes' => ['api']]);
    expect(compileFixtures()->names())->toContain('list_courses', 'create_course');
});

describe('filtering by host and by middleware', function (): void {
    it('discovers only the hosts the application named', function (): void {
        config(['mcp-bridge.discovery.domains' => ['api.example.test']]);

        Route::domain('api.example.test')->prefix('api')->group(function (): void {
            Route::get('tenanted', [CourseController::class, 'index'])->name('tenanted.index');
        });

        $names = compileFixtures()->names();

        expect($names)->toContain('list_tenanteds')
            // The fixture routes are on no host, so naming one excludes them.
            ->and($names)->not->toContain('list_courses');
    });

    it('discovers only routes carrying the middleware the application insists on', function (): void {
        // The cheapest way to say that nothing unauthenticated becomes a tool.
        config(['mcp-bridge.discovery.middleware' => ['auth:*']]);

        Route::middleware('auth:sanctum')->prefix('api')->group(function (): void {
            Route::get('guarded', [CourseController::class, 'index'])->name('guarded.index');
        });

        $names = compileFixtures()->names();

        expect($names)->toContain('list_guardeds')
            ->and($names)->not->toContain('list_courses');
    });
});
