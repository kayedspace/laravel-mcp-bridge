<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Schema;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Wire;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * An Artisan command's signature as tool inputs.
 *
 * A command signature already says everything a schema needs: which values are
 * required, which take a list, which are flags, what each one is for and what
 * it defaults to. That is the declaration, so it is the schema.
 */
class SignatureMapper
{
    /**
     * Symfony and Laravel add these to every command. None of them is an
     * agent's decision, and `--no-interaction` is set by the executor itself.
     */
    protected const GLOBAL_OPTIONS = [
        'help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi',
        'no-interaction', 'env', 'silent', 'profile',
    ];

    public function forCommand(Command $command): InputSchema
    {
        $definition = $command->getDefinition();
        $schema = InputSchema::empty();
        $position = 0;

        foreach ($definition->getArguments() as $argument) {
            $schema = $schema->with(...$this->argument($argument, $position++));
        }

        foreach ($definition->getOptions() as $option) {
            if (in_array($option->getName(), self::GLOBAL_OPTIONS, true)) {
                continue;
            }

            $schema = $schema->with(...$this->option($option));
        }

        return $schema->sorted();
    }

    /** @return list<Field> */
    protected function argument(InputArgument $argument, int $position): array
    {
        $name = $this->path($argument->getName());
        $wire = new Wire(
            in: WireIn::CommandArgument,
            name: $argument->getName(),
            position: $position,
        );

        if ($argument->isArray()) {
            return [
                new Field(
                    path: $name,
                    type: FieldType::Array,
                    required: $argument->isRequired(),
                    description: $this->describe($argument->getDescription()),
                    default: $this->default($argument->getDefault()),
                    wire: $wire,
                    origin: ['signature:argument'],
                ),
                new Field(path: $name.'.*', type: FieldType::String, origin: ['signature:argument']),
            ];
        }

        return [new Field(
            path: $name,
            type: FieldType::String,
            required: $argument->isRequired(),
            description: $this->describe($argument->getDescription()),
            default: $this->default($argument->getDefault()),
            wire: $wire,
            origin: ['signature:argument'],
        )];
    }

    /** @return list<Field> */
    protected function option(InputOption $option): array
    {
        $name = $this->path($option->getName());

        // An option that takes no value is a flag: the agent sends true or
        // false, and the executor decides whether to pass the switch at all.
        $flag = ! $option->acceptValue();

        $wire = new Wire(
            in: WireIn::CommandOption,
            name: $option->getName(),
            flag: $flag,
        );

        if ($option->isArray()) {
            return [
                new Field(
                    path: $name,
                    type: FieldType::Array,
                    description: $this->describe($option->getDescription()),
                    default: $this->default($option->getDefault()),
                    wire: $wire,
                    origin: ['signature:option'],
                ),
                new Field(path: $name.'.*', type: FieldType::String, origin: ['signature:option']),
            ];
        }

        return [new Field(
            path: $name,
            type: $flag ? FieldType::Boolean : FieldType::String,
            required: $option->isValueRequired() && $option->getDefault() === null,
            description: $this->describe($option->getDescription()),
            default: $this->default($option->getDefault()),
            wire: $wire,
            origin: ['signature:option'],
        )];
    }

    /** A dashed option name is not a legal schema property, so it travels as an underscore. */
    protected function path(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    protected function describe(string $description): ?string
    {
        return $description === '' ? null : $description;
    }

    /** Only a value an agent could actually send back is worth advertising. */
    protected function default(mixed $default): mixed
    {
        return is_scalar($default) || (is_array($default) && $default !== []) ? $default : null;
    }
}
