<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Integrations;

use Dedoc\Scramble\Configuration\ParametersExtractors;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use KayedSpace\McpBridge\Contracts\ReadsQueryDefinitions;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Query\QuerySchema;

/**
 * The other direction: this package's declarations feed Scramble's OpenAPI
 * document.
 *
 * A team that annotates for agents first and wants REST docs second should not
 * have to write the same thing twice, so `#[AllowFilter]`, `#[AllowSort]`,
 * `#[AllowInclude]`, `#[AllowFields]` and `#[Paginate]` become real OpenAPI
 * query parameters, with the same names and descriptions the agent sees.
 *
 * Opt-in, in one line in a service provider, because feeding anything into a
 * team's public API document without being asked would be a surprise:
 *
 *     ScrambleParameters::register();
 */
class ScrambleParameters implements ParameterExtractor
{
    public function __construct(
        protected ReadsQueryDefinitions $queries,
        protected QuerySchema $schema,
    ) {}

    public static function register(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::configure()->withParametersExtractors(
            fn (ParametersExtractors $extractors): ParametersExtractors => $extractors->append(self::class),
        );
    }

    /**
     * @param  ParametersExtractionResult[]  $parameterExtractionResults
     * @return ParametersExtractionResult[]
     */
    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        $class = $routeInfo->className();
        $method = $routeInfo->methodName();

        if ($class === null || ! class_exists($class)) {
            return $parameterExtractionResults;
        }

        $definition = $this->queries->read($class, $method);

        if ($definition === null) {
            return $parameterExtractionResults;
        }

        $parameters = $this->parameters($this->schema->forDefinition($definition));

        return $parameters === []
            ? $parameterExtractionResults
            : [...$parameterExtractionResults, new ParametersExtractionResult(
                parameters: $parameters,
                description: 'Query parameters declared for agent tools.',
                sourceClass: $class,
            )];
    }

    /**
     * The members of a list are described on the list itself, so only the
     * fields that travel as a parameter of their own are emitted.
     *
     * @return list<Parameter>
     */
    protected function parameters(InputSchema $schema): array
    {
        $parameters = [];

        foreach ($schema->sorted()->fields as $path => $field) {
            if ($field->hidden || str_contains($path, '*') || $field->wire?->in !== WireIn::Query) {
                continue;
            }

            $parameters[] = $this->parameter($field, $schema);
        }

        return $parameters;
    }

    /**
     * Scramble's generator objects mutate and return themselves, and several
     * of their setters declare no return type, so each call is a statement
     * rather than a chain.
     */
    protected function parameter(Field $field, InputSchema $schema): Parameter
    {
        $parameter = Parameter::make($field->wire->name ?? $field->path, 'query');
        $parameter->required($field->required);

        $parameterSchema = Schema::fromType($this->type($field, $schema));

        // Scramble's factory declares no return type; an unexpected one leaves
        // the parameter described but untyped rather than failing the build.
        if ($parameterSchema instanceof Schema) {
            $parameter->setSchema($parameterSchema);
        }

        if ($field->description !== null) {
            $parameter->description($field->description);
        }

        return $parameter;
    }

    protected function type(Field $field, InputSchema $schema): Type
    {
        $type = $this->scalar($field->type);

        if ($type instanceof ArrayType) {
            // A list's allowed values live on its wildcard member, which is
            // where the agent-facing schema keeps them too.
            $member = $schema->get($field->path.'.*');

            $type->setItems($this->scalar($member?->type, $member->enum ?? []));
        }

        if ($field->enum !== []) {
            $type->enum($field->enum);
        }

        if ($field->format !== null) {
            $type->format($field->format);
        }

        if (is_scalar($field->default) || is_array($field->default)) {
            $type->default($field->default);
        }

        return $type;
    }

    /** @param list<string> $enum */
    protected function scalar(?FieldType $type, array $enum = []): Type
    {
        $scramble = match ($type) {
            FieldType::Integer => new IntegerType,
            FieldType::Number => new NumberType,
            FieldType::Boolean => new BooleanType,
            FieldType::Object => new ObjectType,
            FieldType::Array => new ArrayType,
            default => new StringType,
        };

        if ($enum !== []) {
            $scramble->enum($enum);
        }

        return $scramble;
    }
}
