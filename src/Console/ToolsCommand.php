<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Manifest\ManifestRepository;

class ToolsCommand extends Command
{
    protected $signature = 'mcp-bridge:tools
        {tool? : One tool, with --explain}
        {--source= : Only tools from this source, such as route or command}
        {--module= : Only tools from this module}
        {--server= : Only tools served by this server}
        {--refused : Show the candidates that were found but not exposed, and why}
        {--explain : Where every value on one tool came from}';

    protected $description = 'List the tools this application exposes, and which source each came from';

    public function handle(ManifestRepository $manifest, ManifestCompiler $compiler): int
    {
        if ($this->option('refused')) {
            return $this->showRefusals($compiler);
        }

        if ($this->option('explain')) {
            return $this->explain($manifest);
        }

        $tools = array_filter($manifest->all(), $this->filter());

        if ($tools === []) {
            $this->components->warn($manifest->all() === []
                ? 'No tools are exposed. Run [mcp-bridge:tools --refused] to see what was found and why it was not.'
                : 'No tools matched.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tool', 'Source', 'Module', 'Server', 'Target', 'Safety'],
            array_map(fn (ToolDefinition $tool): array => [
                $tool->name,
                $tool->source,
                $tool->module ?? '',
                $tool->server,
                $tool->target->describe(),
                $this->safety($tool),
            ], array_values($tools)),
        );

        return self::SUCCESS;
    }

    protected function showRefusals(ManifestCompiler $compiler): int
    {
        $refusals = $compiler->compile()->refusals;

        if ($refusals === []) {
            $this->components->info('Every discovered candidate is exposed.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($refusals as $id => $reasons) {
            foreach ($reasons as $reason) {
                /** @var Refusal $reason */
                $rows[] = [$id, $reason->code, $reason->message];
            }
        }

        $this->table(['Candidate', 'Code', 'Reason'], $rows);

        return self::SUCCESS;
    }

    protected function filter(): callable
    {
        $source = $this->option('source');
        $module = $this->option('module');
        $server = $this->option('server');

        return fn (ToolDefinition $tool): bool => ($source === null || $tool->source === $source)
            && ($module === null || $tool->module === $module)
            && ($server === null || $tool->server === $server);
    }

    protected function safety(ToolDefinition $tool): string
    {
        return match (true) {
            $tool->safety->destructive => 'destructive',
            $tool->safety->readOnly => 'read-only',
            default => 'write',
        };
    }

    /**
     * Where each value on one tool came from.
     *
     * A tool is assembled in layers, and when the answer surprises someone
     * the question is always which layer won. Every field records the layers
     * that touched it, by name: `route-param`, `rules`, `scramble:query`,
     * `query:filter`, `attribute:McpParam` and the rest. This is a view of
     * what the compiler wrote down rather than a second guess at it, which is
     * why it cannot disagree with the manifest a client is served.
     *
     * The four options below are coarser than the fields: they say whether a
     * value was declared, generated or configured, and not which of a class
     * and a method declared it. Tracking that costs the attribute reader a
     * provenance it does not otherwise need, and nobody has asked which of
     * the two won.
     */
    protected function explain(ManifestRepository $manifest): int
    {
        $name = $this->argument('tool');

        if (! is_string($name) || $name === '') {
            $this->components->error('Name the tool to explain: mcp-bridge:tools <tool> --explain');

            return self::FAILURE;
        }

        $definition = $manifest->all()[$name] ?? null;

        if (! $definition instanceof ToolDefinition) {
            $this->components->error("No tool named [{$name}] is in the manifest.");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<options=bold>'.$definition->name.'</>', $definition->target->describe());

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Decided by</>', '');

        foreach ($definition->origin as $option => $layer) {
            $this->components->twoColumnDetail("  {$option}", "<fg=gray>{$layer}</>");
        }

        $this->components->twoColumnDetail('  module', '<fg=gray>'.($definition->module ?? 'none').'</>');
        $this->components->twoColumnDetail('  groups', '<fg=gray>'.($definition->groups === [] ? 'none' : implode(', ', $definition->groups)).'</>');
        $this->components->twoColumnDetail('  server', '<fg=gray>'.$definition->server.'</>');
        $this->components->twoColumnDetail('  scopes', '<fg=gray>'.($definition->authority->scopes === [] ? 'none' : implode(', ', $definition->authority->scopes)).'</>');
        $this->components->twoColumnDetail('  abilities', '<fg=gray>'.($definition->authority->abilities === [] ? 'none' : implode(', ', $definition->authority->abilities)).'</>');
        $this->components->twoColumnDetail('  confirmation', '<fg=gray>'.($definition->authority->confirm ? 'required' : 'not required').'</>');
        $this->components->twoColumnDetail('  rate limit', '<fg=gray>'.($definition->limits->rateLimit ?? 'none').'</>');

        if ($definition->input->isEmpty()) {
            $this->newLine();
            $this->components->info('This tool takes no inputs.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Inputs, and the layers that set them</>', '');

        foreach ($definition->input->sorted()->fields as $path => $field) {
            $layers = $field->origin === [] ? 'unrecorded' : implode(' then ', $field->origin);
            $note = $field->hidden ? ' <fg=yellow>hidden</>' : '';

            $this->components->twoColumnDetail("  {$path}", "<fg=gray>{$layers}</>{$note}");
        }

        return self::SUCCESS;
    }
}
