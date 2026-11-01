<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\CommandTarget;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\McpBridgeManager;
use Throwable;

/**
 * Runs a command tool through the console kernel, which is the same entry
 * point `php artisan` uses, so the command behaves exactly as it does at a
 * shell except for three things this executor insists on:
 *
 * interaction is off, so a command that would prompt fails instead of hanging
 * on a question nobody will answer; output is captured rather than written to
 * a terminal nobody is watching; and the call runs as the agent's own user,
 * because a command's normal caller is an operator at a shell and inheriting
 * that identity would be privilege escalation by design.
 */
class CommandExecutor implements ExecutesTools
{
    public function __construct(
        protected Application $app,
        protected Kernel $kernel,
        protected McpBridgeManager $bridge,
        protected Repository $config,
    ) {}

    public function execute(ToolCall $call): SourceResult
    {
        $target = $call->definition->target;

        if (! $target instanceof CommandTarget) {
            return SourceResult::failed(500, ['message' => 'This tool is not backed by a command.']);
        }

        // Buffered for the result, and streamed line by line to a caller
        // that runs this inside a fiber it registered for it.
        $output = new StreamedOutput;
        $snapshot = StateSnapshot::capture($this->app);

        try {
            $exitCode = $this->bridge->actingAs(
                $call->actor,
                fn (): int => $this->kernel->call($target->name, $this->parameters($call), $output),
            );

            return $this->result($target, $exitCode, $output->fetch());
        } catch (Throwable $exception) {
            return SourceResult::failed(500, [
                'message' => "The command [{$target->name}] could not be run.",
            ], $exception, ['code' => 'MCP_BRIDGE_COMMAND_FAILED']);
        } finally {
            $snapshot->restore();
        }
    }

    /**
     * Agent input as console parameters: arguments by name, options with a
     * leading `--`, and a flag passed only when it is true, since Symfony
     * reads a present flag as set whatever value follows it.
     *
     * @return array<string, mixed>
     */
    protected function parameters(ToolCall $call): array
    {
        $parameters = ['--no-interaction' => true];

        foreach ($call->definition->input->fields as $field) {
            $in = $field->wire?->in;

            if ($in !== WireIn::CommandArgument && $in !== WireIn::CommandOption) {
                continue;
            }

            if (str_contains($field->path, '*')) {
                continue;
            }

            // Filled from the actor, not from the agent; every other hidden
            // field is the application's to fill, never the agent's.
            $value = $field->filledFrom === 'auth'
                ? $call->filledFromActor()
                : ($field->hidden ? null : $call->argument($field->path));

            if ($value === null) {
                continue;
            }

            $this->set($parameters, $field, $value);
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function set(array &$parameters, Field $field, mixed $value): void
    {
        $wire = $field->wire;

        if ($wire === null) {
            return;
        }

        if ($wire->in === WireIn::CommandArgument) {
            $parameters[$wire->name] = $value;

            return;
        }

        if (! $wire->flag) {
            $parameters['--'.$wire->name] = $value;

            return;
        }

        // A flag is the switch itself, so false means leave it out entirely.
        if ($value === true || $value === 'true' || $value === 1 || $value === '1') {
            $parameters['--'.$wire->name] = true;
        }
    }

    /**
     * Exit code 0 is success. Anything else is a failure the agent can act on,
     * which means it needs the code and what the command actually printed.
     */
    protected function result(CommandTarget $target, int $exitCode, string $output): SourceResult
    {
        $captured = $this->config->boolean('mcp-bridge.sources.command.capture_output', true)
            ? trim($output)
            : '';

        if ($exitCode === 0) {
            return SourceResult::ok(
                ['output' => $captured],
                ['exit_code' => 0, 'command' => $target->name],
            );
        }

        return SourceResult::failed(500, [
            'message' => "The command [{$target->name}] exited with code {$exitCode}."
                .($captured === '' ? '' : "\n".$this->tail($captured)),
        ], null, ['code' => 'MCP_BRIDGE_COMMAND_FAILED', 'exit_code' => $exitCode]);
    }

    /**
     * The end of the output, because a failing command says what went wrong
     * last, and the whole log would spend the agent's context on the part that
     * went fine.
     */
    protected function tail(string $output): string
    {
        $lines = explode("\n", $output);
        $keep = $this->config->integer('mcp-bridge.sources.command.output_tail_lines', 20);

        return implode("\n", array_slice($lines, -max($keep, 1)));
    }
}
