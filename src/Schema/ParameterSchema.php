<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Data\InputSchema;
use Spatie\LaravelData\Data;

/**
 * What a constructor could be read as, and what it could not.
 *
 * The unsupported list is the point: a job whose constructor takes a closure
 * or an untyped value cannot be built from JSON, so it is refused at compile
 * time with the parameter named, rather than failing at the first call.
 */
final readonly class ParameterSchema
{
    /**
     * @param  list<string>  $unsupported  parameter names that cannot come from an agent, with the reason
     * @param  array<string, class-string<Model>>  $models  parameters the executor resolves from a key, by class
     * @param  array<string, class-string>  $coerce  parameters that arrive as a scalar and have to become an
     *                                               object or an enum case before the constructor will take them
     * @param  array<string, class-string<Data>>  $data  parameters typed as a spatie/laravel-data object, whose
     *                                                   fields sit flat in the input and which the executor
     *                                                   builds from them, validated by the object's own rules
     */
    public function __construct(
        public InputSchema $input,
        public array $unsupported = [],
        public array $models = [],
        public array $coerce = [],
        public array $data = [],
    ) {}

    public function isUsable(): bool
    {
        return $this->unsupported === [];
    }
}
