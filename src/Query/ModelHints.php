<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Query;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Enums\FieldType;
use Throwable;

/**
 * What a model already says about its own columns.
 *
 * A filter on a cast attribute is typed from the cast, and one on a backed
 * enum gets the enum's values, so an agent sends `published` rather than
 * guessing. Instantiating the model is enough for this: no query runs.
 *
 * ponytail: casts and backed enums only. Reading real column types means a
 * live connection during compilation, which a build box may not have; add it
 * behind a config flag if casts turn out not to be enough.
 */
final readonly class ModelHints
{
    /** @param array<string, string> $casts */
    private function __construct(
        public ?string $table = null,
        public array $casts = [],
    ) {}

    /** @param class-string|null $model */
    public static function for(?string $model): self
    {
        if ($model === null || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return new self;
        }

        try {
            $instance = new $model;

            /** @var array<string, string> $casts */
            $casts = array_filter($instance->getCasts(), 'is_string');

            return new self(table: $instance->getTable(), casts: $casts);
        } catch (Throwable) {
            // A model whose constructor needs more than this cannot be read,
            // which only costs the inferred types.
            return new self;
        }
    }

    public function type(string $attribute): ?FieldType
    {
        $cast = $this->cast($attribute);

        if ($cast === null) {
            return null;
        }

        if ($this->enumValues($attribute) !== []) {
            return FieldType::String;
        }

        return match (true) {
            in_array($cast, ['int', 'integer'], true) => FieldType::Integer,
            in_array($cast, ['real', 'float', 'double'], true) => FieldType::Number,
            str_starts_with($cast, 'decimal') => FieldType::Number,
            in_array($cast, ['bool', 'boolean'], true) => FieldType::Boolean,
            in_array($cast, ['array', 'json', 'object', 'collection'], true) => FieldType::Array,
            default => FieldType::String,
        };
    }

    public function format(string $attribute): ?string
    {
        $cast = $this->cast($attribute);

        if ($cast === null) {
            return null;
        }

        return match (true) {
            $cast === 'date' || $cast === 'immutable_date' => 'date',
            str_starts_with($cast, 'datetime') => 'date-time',
            str_starts_with($cast, 'immutable_datetime') => 'date-time',
            $cast === 'timestamp' => 'date-time',
            default => null,
        };
    }

    /**
     * The values of a backed enum cast, which are exactly the values the
     * agent may send.
     *
     * @return list<string>
     */
    public function enumValues(string $attribute): array
    {
        $cast = $this->cast($attribute);

        return $cast === null || ! enum_exists($cast) ? [] : self::valuesOf($cast);
    }

    /**
     * @param  class-string  $enum
     * @return list<string>
     */
    public static function valuesOf(string $enum): array
    {
        if (! enum_exists($enum)) {
            return [];
        }

        $values = [];

        foreach ($enum::cases() as $case) {
            $values[] = (string) ($case instanceof BackedEnum ? $case->value : $case->name);
        }

        return $values;
    }

    /**
     * A filter may be dotted (`author.name`); the cast, if any, belongs to the
     * attribute itself. Parameterised casts (`decimal:2`, `datetime:Y-m-d`)
     * are kept whole, since every test above is a prefix test.
     */
    protected function cast(string $attribute): ?string
    {
        return $this->casts[$attribute] ?? null;
    }
}
