<?php

declare(strict_types=1);

use KayedSpace\McpBridge\Data\CommandTarget;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Manifest\ManifestRepository;

beforeEach(function () {
    $this->manifest = app(ManifestRepository::class);
    $this->manifest->clear();
});

it('compiles from the application when nothing is cached', function () {
    config(['mcp-bridge.mode' => 'opt-in']);
    defineApiRoutes();

    // The cache is an optimisation, as route caching is: a server that
    // listed nothing until someone ran mcp-bridge:cache was the first thing
    // every new user hit.
    expect($this->manifest->isCached())->toBeFalse()
        ->and($this->manifest->find('list_courses'))->not->toBeNull()
        ->and($this->manifest->isCached())->toBeFalse();
});

it('answers an application with nothing to expose with an empty manifest', function () {
    expect($this->manifest->all())->toBe([])
        ->and($this->manifest->find('list_courses'))->toBeNull();
});

it('ignores the file when discovery.cache is off', function () {
    config(['mcp-bridge.mode' => 'opt-in', 'mcp-bridge.discovery.cache' => false]);
    defineApiRoutes();

    $this->manifest->write([definition(name: 'stale_tool')]);

    // A developer who turned the cache off wants what the code says now,
    // not what the file said when it was last written.
    expect(app(ManifestRepository::class)->find('stale_tool'))->toBeNull()
        ->and(app(ManifestRepository::class)->find('list_courses'))->not->toBeNull();
});

it('round trips definitions through the exported file', function () {
    $input = InputSchema::empty()->with(new Field(path: 'page', type: FieldType::Integer, min: 1));

    $this->manifest->write([definition(input: $input)]);

    $restored = app(ManifestRepository::class)->find('list_courses');

    expect($this->manifest->isCached())->toBeTrue()
        ->and($restored)->not->toBeNull()
        ->and($restored->target->describe())->toBe('GET /api/courses')
        ->and($restored->input->get('page')->min)->toBe(1)
        ->and($restored->safety->readOnly)->toBeTrue();
});

it('writes a byte identical file for the same definitions in any order', function () {
    $a = definition(name: 'list_courses');
    $b = definition(name: 'create_course', safety: Safety::destructive());

    $this->manifest->write([$a, $b]);
    $first = file_get_contents($this->manifest->path());

    $this->manifest->write([$b, $a]);
    $second = file_get_contents($this->manifest->path());

    expect($second)->toBe($first);
});

it('separates tools by server so an operations token cannot see api tools', function () {
    $this->manifest->write([
        definition(name: 'list_courses'),
        definition(
            name: 'recalculate_stats',
            source: 'command',
            target: new CommandTarget('stats:recalculate'),
            server: 'ops',
        ),
    ]);

    expect(array_keys($this->manifest->forServer('default')))->toBe(['list_courses'])
        ->and(array_keys($this->manifest->forServer('ops')))->toBe(['recalculate_stats']);
});
