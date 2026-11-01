<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;

it('lets a later layer win while keeping the origin trail', function () {
    $fromRules = new Field(
        path: 'filter.author',
        type: FieldType::String,
        description: 'Author id.',
        origin: ['rules:string'],
    );

    $fromScramble = new Field(
        path: 'filter.author',
        type: FieldType::String,
        description: 'Only what this user wrote: pass their id.',
        origin: ['scramble:QueryParameter'],
    );

    $schema = InputSchema::empty()->with($fromRules)->with($fromScramble);
    $field = $schema->get('filter.author');

    expect($field->description)->toBe('Only what this user wrote: pass their id.')
        ->and($field->origin)->toBe(['rules:string', 'scramble:QueryParameter']);
});

it('keeps a required flag set by any layer', function () {
    $schema = InputSchema::empty()
        ->with(new Field(path: 'course', type: FieldType::Integer, required: true))
        ->with(new Field(path: 'course', type: FieldType::Integer, description: 'The course id.'));

    expect($schema->get('course')->required)->toBeTrue()
        ->and($schema->get('course')->description)->toBe('The course id.');
});

it('orders fields deterministically', function () {
    $schema = InputSchema::empty()
        ->with(new Field(path: 'sort', type: FieldType::Array))
        ->with(new Field(path: 'filter.title', type: FieldType::String))
        ->with(new Field(path: 'page', type: FieldType::Integer));

    expect(array_keys($schema->sorted()->fields))->toBe(['filter.title', 'page', 'sort']);
});

it('records constraints the json schema subset cannot express', function () {
    $field = new Field(
        path: 'expires_at',
        type: FieldType::String,
        format: 'date',
        unexpressible: ['required_if:plan,paid'],
    );

    expect($field->unexpressible)->toContain('required_if:plan,paid');
});

it('survives a round trip through a plain array', function () {
    $schema = InputSchema::empty()->with(new Field(
        path: 'filter.author',
        type: FieldType::String,
        required: true,
        description: 'Author id.',
        wire: new Wire(in: WireIn::Query, name: 'filter[author]'),
        origin: ['scramble:QueryParameter'],
    ));

    $restored = InputSchema::fromArray($schema->toArray());

    expect($restored->toArray())->toBe($schema->toArray())
        ->and($restored->get('filter.author')->wire->name)->toBe('filter[author]');
});
