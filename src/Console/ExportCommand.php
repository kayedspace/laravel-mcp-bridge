<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;

/**
 * The compiled surface as a file, for review, for diffing in a pull request,
 * and for anything that wants to read the tool set without running the app.
 *
 * OpenAPI is deliberately not generated here: when Scramble is installed it
 * already produces that document from the same declarations, and a second,
 * competing one would be worse than none.
 */
class ExportCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:export
        {--format=json : json or markdown}
        {--path= : Write to this file instead of standard output}';

    protected $description = 'Export the compiled tools as JSON or Markdown';

    public function handle(ManifestCompiler $compiler): int
    {
        $definitions = $compiler->compile()->definitions;

        $output = $this->stringOption('format', 'json') === 'markdown'
            ? $this->markdown($definitions)
            : (string) json_encode(
                array_map(fn (ToolDefinition $definition): array => $definition->toArray(), $definitions),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );

        $path = $this->stringOption('path');

        if ($path === null) {
            $this->line($output);

            return self::SUCCESS;
        }

        file_put_contents($path, $output.PHP_EOL);

        $this->components->info(count($definitions)." tools written to [{$path}].");

        return self::SUCCESS;
    }

    /** @param list<ToolDefinition> $definitions */
    protected function markdown(array $definitions): string
    {
        $lines = ['# Tools', ''];

        foreach ($definitions as $definition) {
            $lines[] = "## `{$definition->name}`";
            $lines[] = '';
            $lines[] = $definition->description;
            $lines[] = '';
            $lines[] = "- Source: `{$definition->source}`";
            $lines[] = '- Target: `'.$definition->target->describe().'`';
            $lines[] = '- Safety: '.($definition->safety->destructive
                ? 'destructive'
                : ($definition->safety->readOnly ? 'read-only' : 'write'));

            if ($definition->authority->scopes !== []) {
                $lines[] = '- Scopes: `'.implode('`, `', $definition->authority->scopes).'`';
            }

            $lines[] = '';

            foreach ($definition->input->fields as $path => $field) {
                if ($field->hidden) {
                    continue;
                }

                $lines[] = "  - `{$path}` ({$field->type->value}".($field->required ? ', required' : '').')'
                    .($field->description === null ? '' : ": {$field->description}");
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
