<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Validation\Rule;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Schema\RuleMapper;
use KayedSpace\McpBridge\Tests\Fixtures\CourseStatus;

function mapper(): RuleMapper
{
    return new RuleMapper(new Repository([]), new Container);
}

it('reads the type, the requirement and the bounds', function (): void {
    $field = mapper()->map('per_page', 'required|integer|min:1|max:100', WireIn::Query);

    expect($field->type)->toBe(FieldType::Integer)
        ->and($field->required)->toBeTrue()
        ->and($field->min)->toBe(1)
        ->and($field->max)->toBe(100)
        ->and($field->wire?->name)->toBe('per_page');
});

it('reads sometimes as not required, nullable as nullable, and a decimal as a number', function (): void {
    $field = mapper()->map('price', ['sometimes', 'required', 'nullable', 'decimal:2', 'between:0,99.5'], WireIn::Body);

    expect($field->required)->toBeFalse()
        ->and($field->nullable)->toBeTrue()
        ->and($field->type)->toBe(FieldType::Number)
        ->and($field->min)->toBe(0.0)
        ->and($field->max)->toBe(99.5);
});

it('reads formats, enums and patterns', function (): void {
    expect(mapper()->map('email', 'email', WireIn::Body)->format)->toBe('email')
        ->and(mapper()->map('status', 'in:draft,published', WireIn::Body)->enum)->toBe(['draft', 'published'])
        ->and(mapper()->map('status', [Rule::in(['a', 'b'])], WireIn::Body)->enum)->toBe(['a', 'b'])
        ->and(mapper()->map('status', [Rule::enum(CourseStatus::class)], WireIn::Body)->enum)->toBe(['draft', 'published', 'archived'])
        ->and(mapper()->map('slug', 'regex:/^[a-z-]+$/', WireIn::Body)->pattern)->toBe('^[a-z-]+$');
});

it('keeps what a schema cannot say as prose rather than dropping or guessing it', function (): void {
    $field = mapper()->map('ends_on', 'date|after:starts_on|required_if:recurring,true', WireIn::Body);

    expect($field->format)->toBe('date')
        ->and($field->unexpressible)->toBe(['after:starts_on', 'required_if:recurring,true']);
});

it('nests a query path in brackets on the wire and leaves a body path alone', function (): void {
    expect(mapper()->map('filter.status', 'string', WireIn::Query)->wire?->name)->toBe('filter[status]')
        ->and(mapper()->map('address.city', 'string', WireIn::Body)->wire?->name)->toBe('address.city');
});

it('types a wildcard member rather than the list, and the list from its rule', function (): void {
    expect(mapper()->map('tags', 'list', WireIn::Body)->type)->toBe(FieldType::Array)
        ->and(mapper()->map('tags.*', 'string|max:20', WireIn::Body)->type)->toBe(FieldType::String)
        ->and(mapper()->map('meta', 'array', WireIn::Body)->type)->toBe(FieldType::Object);
});
