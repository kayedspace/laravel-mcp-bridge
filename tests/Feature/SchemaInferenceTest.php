<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Schema\JsonSchemaCompiler;
use KayedSpace\McpBridge\Schema\RuleMapper;
use KayedSpace\McpBridge\Tests\Fixtures\PostcodeMapper;
use KayedSpace\McpBridge\Tests\Fixtures\PostcodeRule;

beforeEach(fn () => config(['mcp-bridge.mode' => 'opt-in']));

it('builds a typed field for every form request rule', function () {
    $input = toolNamed('create_course')->input;

    expect($input->get('title')->type)->toBe(FieldType::String)
        ->and($input->get('title')->required)->toBeTrue()
        ->and($input->get('title')->max)->toBe(255)
        ->and($input->get('price')->type)->toBe(FieldType::Number)
        ->and($input->get('price')->min)->toBe(0.0)
        ->and($input->get('status')->enum)->toBe(['draft', 'published'])
        ->and($input->get('summary')->nullable)->toBeTrue();
});

it('types route parameters from their constraints', function () {
    $input = toolNamed('get_course')->input;

    expect($input->get('course')->type)->toBe(FieldType::Integer)
        ->and($input->get('course')->required)->toBeTrue()
        ->and($input->get('course')->wire->in)->toBe(WireIn::Path);
});

it('requires both ids on a nested route', function () {
    $input = toolNamed('get_course_lesson')->input;

    expect($input->get('course')->required)->toBeTrue()
        ->and($input->get('lesson')->required)->toBeTrue();
});

it('keeps dotted rules as nested paths with bracket wire names', function () {
    $input = toolNamed('list_courses')->input;

    expect($input->get('filter.title')->type)->toBe(FieldType::String)
        ->and($input->get('filter.title')->max)->toBe(100)
        ->and($input->get('filter.title')->wire->name)->toBe('filter[title]')
        ->and($input->get('filter.status')->enum)->toBe(['draft', 'published', 'archived']);
});

it('sends write inputs in the body and read inputs in the query', function () {
    expect(toolNamed('create_course')->input->get('title')->wire->in)->toBe(WireIn::Body)
        ->and(toolNamed('list_courses')->input->get('per_page')->wire->in)->toBe(WireIn::Query);
});

it('records a rule the schema subset cannot express instead of dropping it', function () {
    $field = toolNamed('create_course')->input->get('starts_at');

    expect($field->unexpressible)->toContain('required_if:status,published')
        ->and($field->format)->toBe('date');
});

it('hides fields the application fills from the authenticated user', function () {
    $field = toolNamed('create_course')->input->get('user_id');

    expect($field->hidden)->toBeTrue()
        ->and($field->filledFrom)->toBe('auth')
        ->and($field->required)->toBeFalse();
});

it('records where each field came from', function () {
    expect(toolNamed('get_course')->input->get('course')->origin)->toBe(['route-param'])
        ->and(toolNamed('create_course')->input->get('title')->origin)->toBe(['rules'])
        ->and(toolNamed('create_course')->input->get('user_id')->origin)->toBe(['rules', 'fill_from_auth']);
});

it('compiles the intermediate representation into illuminate json schema types', function () {
    $compiled = app(JsonSchemaCompiler::class)->compile(
        toolNamed('list_courses')->input,
        new JsonSchemaTypeFactory,
    );

    $filter = $compiled['filter']->toArray();

    expect(array_keys($compiled))->toContain('filter', 'per_page')
        ->and($filter['type'])->toBe('object')
        ->and($filter['properties']['title']['type'])->toBe('string')
        ->and($filter['properties']['title']['maxLength'])->toBe(100)
        ->and($filter['properties']['status']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($compiled['per_page']->toArray())->toMatchArray([
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 50,
        ]);
});

it('never compiles a hidden field into the agent facing schema', function () {
    $compiled = app(JsonSchemaCompiler::class)->compile(
        toolNamed('create_course')->input,
        new JsonSchemaTypeFactory,
    );

    expect($compiled)->not->toHaveKey('user_id')
        ->and($compiled)->toHaveKeys(['title', 'status', 'price']);
});

it('compiles a wildcard rule into an array of items', function () {
    $compiled = app(JsonSchemaCompiler::class)->compile(
        toolNamed('create_course')->input,
        new JsonSchemaTypeFactory,
    );

    expect($compiled['tags']->toArray())->toMatchArray([
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]);
});

it('puts an unexpressible constraint into the description', function () {
    $compiled = app(JsonSchemaCompiler::class)->compile(
        toolNamed('create_course')->input,
        new JsonSchemaTypeFactory,
    );

    expect($compiled['starts_at']->toArray()['description'])->toContain('required_if:status,published');
});

it('hides the twin that a confirmed rule demands, and sends it anyway', function () {
    $input = toolNamed('create_course')->input;

    expect($input->get('access_code')?->hidden)->toBeFalse()
        ->and($input->get('access_code_confirmation'))->not->toBeNull()
        // Present so the executor knows its wire, invisible so the agent is
        // never asked to reproduce a form's own paperwork.
        ->and($input->get('access_code_confirmation')?->hidden)->toBeTrue()
        ->and($input->get('access_code_confirmation')?->filledFrom)->toBe('confirmation');
});

describe('custom rule mappers', function () {
    it('lets an application say what its own rule means', function () {
        config(['mcp-bridge.schema.rule_mappers' => [PostcodeMapper::class]]);

        $field = app(RuleMapper::class)->map('postcode', ['required', new PostcodeRule], WireIn::Body);

        expect($field->pattern)->toBe('^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$')
            ->and($field->description)->toContain('UK postcode')
            // Without a mapper this is all the agent would have been told.
            ->and($field->unexpressible)->toBe([]);
    });

    it('falls back to saying a rule cannot be expressed when nothing maps it', function () {
        $field = app(RuleMapper::class)->map('postcode', ['required', new PostcodeRule], WireIn::Body);

        expect($field->pattern)->toBeNull()
            ->and($field->unexpressible)->toContain(PostcodeRule::class);
    });

    it('ignores a configured name that is not a mapper', function () {
        // A typo should not stop a manifest building.
        config(['mcp-bridge.schema.rule_mappers' => [PostcodeRule::class, 'App\\Gone']]);

        $field = app(RuleMapper::class)->map('title', 'required|string|max:10', WireIn::Body);

        expect($field->max)->toBe(10);
    });
});
