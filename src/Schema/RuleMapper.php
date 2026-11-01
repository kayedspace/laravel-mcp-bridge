<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use BackedEnum;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\Rules\In as InRule;
use KayedSpace\McpBridge\Contracts\MapsRules;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Uploads\Uploads;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * Translates one field's validation rules into the intermediate
 * representation.
 *
 * Anything the mapping cannot express is recorded on the field rather than
 * dropped or guessed at, so a schema is never wrong, only sometimes less
 * specific than the rules behind it.
 */
class RuleMapper
{
    protected const FORMATS = [
        'email' => 'email',
        'url' => 'uri',
        'uuid' => 'uuid',
        'ulid' => 'ulid',
        'ip' => 'ipv4',
        'date' => 'date',
    ];

    /** @var list<MapsRules>|null */
    protected ?array $mappers = null;

    public function __construct(
        protected Repository $config,
        protected Container $container,
    ) {}

    /**
     * The mappers an application registered, resolved once because a mapper
     * is a reader and a manifest is compiled in one pass.
     *
     * A configured name that is not a mapper is skipped rather than thrown:
     * a typo should not stop a manifest building, and nothing an agent sees
     * depends on it.
     *
     * @return list<MapsRules>
     */
    protected function mappers(): array
    {
        if ($this->mappers !== null) {
            return $this->mappers;
        }

        $mappers = [];

        foreach ($this->config->array('mcp-bridge.schema.rule_mappers', []) as $mapper) {
            if (is_string($mapper) && is_a($mapper, MapsRules::class, true)) {
                $resolved = $this->container->make($mapper);

                if ($resolved instanceof MapsRules) {
                    $mappers[] = $resolved;
                }
            }
        }

        return $this->mappers = $mappers;
    }

    /**
     * @param  string|array<int, mixed>  $rules
     */
    public function map(string $path, string|array $rules, WireIn $in): Field
    {
        $parsed = $this->normalise($rules);

        $type = $this->type($path, $parsed);

        $field = new Field(
            path: $path,
            type: $type,
            required: $this->has($parsed, 'required') && ! $this->has($parsed, 'sometimes'),
            nullable: $this->has($parsed, 'nullable'),
            wire: new Wire(in: $in, name: $this->wireName($path, $in)),
            origin: ['rules'],
        );

        foreach ($parsed as $rule) {
            $field = $this->apply($field, $rule, $type);
        }

        return $field;
    }

    /**
     * @param  string|array<int, mixed>  $rules
     * @return list<mixed>
     */
    protected function normalise(string|array $rules): array
    {
        if (is_string($rules)) {
            return array_values(array_filter(explode('|', $rules)));
        }

        return array_values($rules);
    }

    /** @param list<mixed> $rules */
    protected function has(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && Str::before($rule, ':') === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `.*` path describes the member, not the collection: `tags.*` being a
     * string is what makes `tags` an array of strings, and the compiler adds
     * the array around it.
     *
     * @param  list<mixed>  $rules
     */
    protected function type(string $path, array $rules): FieldType
    {
        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            $name = Str::before($rule, ':');

            $type = match ($name) {
                'integer', 'int' => FieldType::Integer,
                'numeric', 'decimal' => FieldType::Number,
                'boolean', 'bool', 'accepted', 'declined' => FieldType::Boolean,
                'array' => FieldType::Object,
                'list' => FieldType::Array,
                default => null,
            };

            if ($type !== null) {
                return $type;
            }
        }

        return FieldType::String;
    }

    protected function apply(Field $field, mixed $rule, FieldType $type): Field
    {
        // Asked first, so an application can say what its own rule means and
        // can also correct how one of Laravel's is read, without replacing
        // the mapping for everything else.
        foreach ($this->mappers() as $mapper) {
            if ($mapper->handles($rule)) {
                return $mapper->map($field, $rule);
            }
        }

        if ($rule instanceof InRule) {
            return $this->withEnum($field, $this->inValues($rule));
        }

        if ($rule instanceof EnumRule) {
            return $this->withEnum($field, $this->enumValues($rule));
        }

        // Closures and custom rule objects carry logic no schema can express.
        if (is_object($rule)) {
            return $this->unexpressible($field, $rule::class);
        }

        if (! is_string($rule)) {
            return $field;
        }

        $name = Str::before($rule, ':');
        $argument = Str::contains($rule, ':') ? Str::after($rule, ':') : null;

        return match ($name) {
            'email', 'url', 'uuid', 'ulid', 'ip', 'date' => $this->withFormat($field, self::FORMATS[$name]),
            'in' => $this->withEnum($field, $this->csv($argument)),
            'min' => $this->withBound($field, $type, min: $argument),
            'max' => $this->withBound($field, $type, max: $argument),
            'size' => $this->withBound($field, $type, min: $argument, max: $argument),
            'between' => $this->between($field, $type, $argument),
            'regex' => $this->withPattern($field, $argument),
            'exists' => $this->exists($field, $argument),
            'file', 'image', 'mimes', 'mimetypes', 'dimensions' => $this->upload($field, $rule),
            'confirmed' => $this->note($field, $rule),
            'required_if', 'required_unless', 'required_with', 'required_with_all',
            'required_without', 'required_without_all', 'prohibited_if', 'same',
            'different', 'gt', 'gte', 'lt', 'lte', 'after', 'before',
            'after_or_equal', 'before_or_equal', 'date_format' => $this->unexpressible($field, $rule),
            default => $field,
        };
    }

    /** @param list<string> $values */
    protected function withEnum(Field $field, array $values): Field
    {
        if ($values === []) {
            return $field;
        }

        return $field->with(enum: $values);
    }

    protected function withFormat(Field $field, string $format): Field
    {
        return $field->with(format: $format);
    }

    protected function withPattern(Field $field, ?string $pattern): Field
    {
        if ($pattern === null || $pattern === '') {
            return $field;
        }

        // Only a delimited PCRE converts safely; anything exotic stays prose.
        if (! preg_match('#^/(.*)/[a-zA-Z]*$#', $pattern, $matches)) {
            return $this->unexpressible($field, "regex:{$pattern}");
        }

        return $field->with(pattern: $matches[1]);
    }

    protected function withBound(Field $field, FieldType $type, ?string $min = null, ?string $max = null): Field
    {
        $number = fn (?string $value): int|float|null => $value === null || ! is_numeric($value)
            ? null
            : ($type === FieldType::Number ? (float) $value : (int) $value);

        return $field->with(min: $number($min), max: $number($max));
    }

    protected function between(Field $field, FieldType $type, ?string $argument): Field
    {
        $parts = $this->csv($argument);

        if (count($parts) !== 2) {
            return $field;
        }

        return $this->withBound($field, $type, min: $parts[0], max: $parts[1]);
    }

    protected function exists(Field $field, ?string $argument): Field
    {
        $table = Str::before((string) $argument, ',');

        if ($table === '') {
            return $field;
        }

        $subject = Str::singular(Str::replace('_', ' ', $table));

        return $field->with(description: $field->description ?? "The id of an existing {$subject}.");
    }

    /**
     * A file input takes the id of a staged upload, not the file.
     *
     * The rule itself stays in the description, because the application's own
     * size and mime limits still apply once the id becomes a real
     * UploadedFile, and an agent that knows them picks a file that will pass.
     */
    protected function upload(Field $field, string $rule): Field
    {
        return $this->unexpressible($field, $rule)->with(
            type: FieldType::String,
            format: Uploads::FORMAT,
            description: $field->description
                ?? 'The id of a file staged with the upload_file tool.',
        );
    }

    protected function note(Field $field, string $rule): Field
    {
        return $this->unexpressible($field, $rule);
    }

    protected function unexpressible(Field $field, string $rule): Field
    {
        return $field->with(unexpressible: [...$field->unexpressible, $rule]);
    }

    /** @return list<string> */
    protected function inValues(InRule $rule): array
    {
        // In stringifies as `in:"a","b"`, which is the documented shape.
        return array_map(
            fn (string $value): string => trim($value, '"'),
            $this->csv(Str::after($rule->__toString(), 'in:')),
        );
    }

    /** @return list<string> */
    protected function enumValues(EnumRule $rule): array
    {
        try {
            $property = new ReflectionProperty($rule, 'type');
            $enum = $property->getValue($rule);

            if (! is_string($enum) || ! enum_exists($enum)) {
                return [];
            }

            return array_map(
                fn (UnitEnum $case): string => $case instanceof BackedEnum ? (string) $case->value : $case->name,
                $enum::cases(),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    protected function csv(?string $argument): array
    {
        if ($argument === null || $argument === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $argument)), fn (string $v): bool => $v !== ''));
    }

    protected function wireName(string $path, WireIn $in): string
    {
        if ($in !== WireIn::Query || ! Str::contains($path, '.')) {
            return $path;
        }

        $segments = explode('.', $path);
        $first = array_shift($segments);

        return $first.implode('', array_map(fn (string $s): string => "[{$s}]", $segments));
    }
}
