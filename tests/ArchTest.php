<?php

declare(strict_types=1);

/**
 * The house rules, checked rather than remembered.
 *
 * Everything here is already true. The point is that it stays true in a
 * package where a debugging aid left in one file reaches an agent, and a
 * config() read at the wrong moment silently returns null under a cached
 * config.
 */
// var_export is how the compiled manifest is written: a PHP file the opcache
// can hold, which is what Laravel's own config:cache and route:cache do. The
// preset reads it as a debugging aid; here it is the serialiser.
arch()->preset()->php()->ignoring('KayedSpace\McpBridge\Manifest\ManifestRepository');

arch('nothing debugging survives a commit')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'var_dump', 'exit', 'die'])
    ->not->toBeUsed();

arch('env() belongs in config, which is read once and may be cached')
    ->expect('env')
    ->not->toBeUsed();

// What a class needs, it is given. The helpers reach into the container
// from anywhere, which reads fine until a class is built outside a request
// or under a cached configuration and quietly gets something else. The
// exceptions are the two Eloquent pieces, which Laravel builds itself, the
// two job middleware, which the queue unserialises with no constructor to
// inject through, and the testing trait, which lives in the application's
// test case.
arch('what a class needs, it is given')
    ->expect('KayedSpace\McpBridge')
    ->not->toUse(['app', 'config', 'request', 'auth', 'resolve'])
    ->ignoring([
        'KayedSpace\McpBridge\Models\McpToken',
        'KayedSpace\McpBridge\Models\Concerns\OnBridgeConnection',
        'KayedSpace\McpBridge\Execution\ReauthorizesJob',
        'KayedSpace\McpBridge\Execution\TracksJobStatus',
        'KayedSpace\McpBridge\Testing\InteractsWithMcp',
    ]);

arch('every file declares strict types')
    ->expect('KayedSpace\McpBridge')
    ->toUseStrictTypes();

arch('data objects stay data')
    ->expect('KayedSpace\McpBridge\Data')
    ->toBeReadonly()
    // Two that are not values: Target is the interface they implement, and
    // Targets is the factory that reads one back from storage.
    ->ignoring([
        'KayedSpace\McpBridge\Data\Target',
        'KayedSpace\McpBridge\Data\Targets',
    ]);

arch('attributes are attributes')
    ->expect('KayedSpace\McpBridge\Attributes')
    ->toHaveAttribute(Attribute::class);

arch('contracts are interfaces, and nothing else is')
    ->expect('KayedSpace\McpBridge\Contracts')
    ->toBeInterfaces();

arch('enums carry no behaviour beyond naming things')
    ->expect('KayedSpace\McpBridge\Enums')
    ->toBeEnums();
