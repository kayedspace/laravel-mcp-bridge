<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use KayedSpace\McpBridge\Discovery\ToolNamer;

/** @param array<string, mixed> $naming */
function namer(array $naming = [], array $prefixes = ['api']): ToolNamer
{
    return new ToolNamer(new Repository(['mcp-bridge' => [
        'discovery' => ['prefixes' => $prefixes, 'strip_version' => true],
        'naming' => [
            'pattern' => '{verb}_{resource}',
            'style' => 'snake',
            'verbs' => ['index' => 'list', 'show' => 'get', 'store' => 'create', 'update' => 'update', 'destroy' => 'delete'],
            'source_prefixes' => ['model' => 'model'],
            ...$naming,
        ],
    ]]));
}

function namedRoute(string $uri, ?string $name = null): Route
{
    $route = new Route(['GET'], $uri, fn () => null);

    return $name === null ? $route : $route->name($name);
}

it('names a route from its name, singular parents and a plural subject', function (string $name, string $expected): void {
    expect(namer()->forRoute(namedRoute('api/v1/x', $name), 'GET'))->toBe($expected);
})->with([
    'collection' => ['api.v1.courses.index', 'list_courses'],
    'member' => ['api.v1.courses.show', 'get_course'],
    'nested collection' => ['api.v1.courses.lessons.index', 'list_course_lessons'],
    'custom action is the verb' => ['api.v1.courses.publish', 'publish_course'],
    'version segment stripped' => ['v2.reports.index', 'list_reports'],
]);

it('names a nameless route from its method and path', function (): void {
    expect(namer()->forRoute(namedRoute('api/v1/courses/{course}/lessons'), 'POST'))->toBe('create_courses_lessons')
        ->and(namer()->forRoute(namedRoute('api/health'), 'GET'))->toBe('get_health');
});

it('names commands, classes, models and operations', function (): void {
    expect(namer()->forCommand('stats:recalculate'))->toBe('recalculate_stats')
        ->and(namer()->forCommand('reports:monthly:send'))->toBe('send_reports_monthly')
        ->and(namer()->forClass('App\Jobs\SendInvoiceJob'))->toBe('send_invoice')
        ->and(namer()->forClass('App\Actions\Job'))->toBe('job')
        ->and(namer()->forModel('App\Models\CourseLesson', 'list'))->toBe('list_course_lessons')
        ->and(namer()->forModel('App\Models\Course', 'get'))->toBe('get_course')
        ->and(namer()->forOperation('GET', '/invoices/{id}', 'getInvoiceById'))->toBe('get_invoice_by_id')
        ->and(namer()->forOperation('DELETE', '/invoices/{id}', null))->toBe('delete_invoices');
});

it('lets an explicit name through, sanitised and capped', function (): void {
    expect(namer()->forCommand('x', 'Recalculate Stats!'))->toBe('recalculate_stats')
        ->and(strlen(namer()->forClass('App\Jobs\X', str_repeat('a', 80))))->toBe(ToolNamer::MAX_LENGTH);
});

it('prefixes a source, in the configured style, and leaves other sources alone', function (): void {
    expect(namer()->prefixed('get_course', 'model'))->toBe('model_get_course')
        ->and(namer()->prefixed('get_course', 'route'))->toBe('get_course')
        ->and(namer(['style' => 'camel'])->prefixed('get_course', 'model'))->toBe('modelGetCourse')
        ->and(namer(['style' => 'kebab'])->prefixed('get_course', 'model'))->toBe('model-get-course')
        ->and(namer(['source_prefixes' => []])->prefixed('get_course', 'model'))->toBe('get_course');
});

it('honours the pattern and the style', function (): void {
    expect(namer(['pattern' => '{resource}_{verb}'])->forRoute(namedRoute('api/x', 'api.courses.index'), 'GET'))->toBe('courses_list')
        ->and(namer(['style' => 'camel'])->forRoute(namedRoute('api/x', 'api.courses.index'), 'GET'))->toBe('listCourses')
        ->and(namer(['style' => 'kebab'])->forRoute(namedRoute('api/x', 'api.courses.index'), 'GET'))->toBe('list-courses');
});
