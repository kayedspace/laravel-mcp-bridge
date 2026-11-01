<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\CommandTarget;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Discovery\AttributeReader;
use KayedSpace\McpBridge\Discovery\CommandFilter;
use KayedSpace\McpBridge\Discovery\ToolNamer;
use KayedSpace\McpBridge\Execution\CommandExecutor;
use KayedSpace\McpBridge\Schema\SignatureMapper;

/**
 * Artisan commands as tools.
 *
 * Unlike a route, a command brings no middleware, no guard and no policy, so
 * nothing here follows the configured mode: every command is opt-in per item,
 * in every mode, and cannot be switched to opt-out by configuration. Exposing
 * a command by pattern match would hand an agent an unprotected entry point.
 *
 * It is also destructive until it says otherwise. A route infers its safety
 * from the HTTP verb; a command has no verb, and what it touches is opaque, so
 * the safe reading is the pessimistic one.
 */
class CommandSource implements Source
{
    public function __construct(
        protected Kernel $kernel,
        protected CommandFilter $filter,
        protected ToolNamer $namer,
        protected AttributeReader $attributes,
        protected SignatureMapper $signature,
        protected CommandExecutor $executor,
        protected Repository $config,
    ) {}

    public function key(): string
    {
        return 'command';
    }

    public function discover(): iterable
    {
        $candidates = [];

        foreach ($this->commands() as $name => $command) {
            $candidate = $this->candidate($name, $command);

            $candidates[$candidate->id] = $candidate;
        }

        // Sorted so an unchanged application always compiles to an identical
        // manifest, whatever order the kernel hands commands back in.
        ksort($candidates);

        return array_values($candidates);
    }

    public function schema(ToolCandidate $candidate): InputSchema
    {
        $command = $this->command($candidate);

        return $command === null
            ? InputSchema::empty()
            : $this->signature->forCommand($command);
    }

    public function validate(ToolCandidate $candidate): array
    {
        $target = $candidate->target;
        $name = $target instanceof CommandTarget ? $target->name : $candidate->id;

        if ($candidate->meta['ignored'] ?? false) {
            return [Refusal::ignored($candidate->id)];
        }

        // Checked before opt-in, so a denied command is refused for being
        // denied rather than for not being opted in, which is the reason a
        // developer needs to read.
        if ($this->filter->isDenied($name)) {
            return [Refusal::deniedCommand($name)];
        }

        if (! $this->filter->isInAllowedNamespace($name)) {
            return [Refusal::outsideNamespaces($name)];
        }

        return ($candidate->meta['opted_in'] ?? false)
            ? []
            : [Refusal::notExposed($candidate->id, 'any')];
    }

    public function execute(ToolCall $call): SourceResult
    {
        return $this->executor->execute($call);
    }

    /** @return array<string, Command> */
    protected function commands(): array
    {
        $commands = [];

        foreach ($this->kernel->all() as $name => $command) {
            // Only a Laravel command can carry this package's attributes, and
            // a bare Symfony command has no opt-in to find.
            if ($command instanceof Command) {
                // PHP turns a numeric-looking key into an int, and a command
                // name is always a string.
                $commands[(string) $name] = $command;
            }
        }

        return $commands;
    }

    protected function candidate(string $name, Command $command): ToolCandidate
    {
        $declarations = $this->attributes->read($command::class);
        $definition = $command->getDefinition();

        return new ToolCandidate(
            source: $this->key(),
            id: "command:{$name}",
            target: new CommandTarget(
                name: $name,
                arguments: array_keys($definition->getArguments()),
                options: array_keys($definition->getOptions()),
            ),
            suggestedName: $this->namer->forCommand($name, $declarations->tool?->name),
            title: $declarations->tool?->title,
            description: $declarations->tool->description ?? $this->describe($command),
            groups: $declarations->groups,
            safety: $this->safety()->with($declarations->safety),
            declarations: $declarations->raw,
            meta: [
                'opted_in' => $declarations->optedIn(),
                'ignored' => $declarations->ignored,
                'command' => $command::class,
            ],
            declared: $declarations->toDeclared(),
        );
    }

    protected function describe(Command $command): ?string
    {
        $description = $command->getDescription();

        return $description === '' ? null : $description;
    }

    /**
     * Destructive unless the command says otherwise, which is the inverse of
     * the HTTP default and deliberate: with `security.destructive` left at
     * `confirm`, a command tool asks for confirmation before it runs.
     */
    protected function safety(): Safety
    {
        $destructive = $this->config->boolean('mcp-bridge.sources.command.destructive_by_default', true);

        return new Safety(
            readOnly: false,
            destructive: $destructive,
            idempotent: false,
            openWorld: false,
        );
    }

    protected function command(ToolCandidate $candidate): ?Command
    {
        $target = $candidate->target;

        if (! $target instanceof CommandTarget) {
            return null;
        }

        return $this->commands()[$target->name] ?? null;
    }
}
