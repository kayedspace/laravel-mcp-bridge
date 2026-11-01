<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Manifest\ManifestRepository;

/**
 * Writing descriptions for an agent, which an LLM is better at than a naming
 * convention is.
 *
 * The work needs an LLM SDK, and that does not belong in the dependency tree
 * of a package an application runs in production for the sake of a thing a
 * developer does once. So it lives in a companion package, and this command
 * exists only to say so: without it, `mcp-bridge:describe` is a command
 * somebody reads about and then cannot find.
 */
class DescribeCommand extends Command
{
    protected $signature = 'mcp-bridge:describe
        {tool? : The tool to describe}
        {--ai : Write the description with an LLM}';

    protected $description = 'Show how a tool describes itself to an agent, or rewrite it with an LLM';

    public function handle(ManifestRepository $manifest): int
    {
        if ($this->option('ai')) {
            $this->components->warn('Writing descriptions needs the companion package.');

            $this->newLine();
            $this->line('  <options=bold>composer require --dev kayedspace/laravel-mcp-bridge-ai</>');
            $this->newLine();
            $this->line('  It is a separate package so that no LLM SDK sits in the dependency');
            $this->line('  tree of an application running this one in production.');

            return self::FAILURE;
        }

        $tools = $manifest->all();

        if ($tools === []) {
            $this->components->warn('No tools are exposed. Run [mcp-bridge:tools --refused] to see what was found and why it was not.');

            return self::SUCCESS;
        }

        $name = $this->argument('tool');

        if (is_string($name) && $name !== '') {
            $tool = $tools[$name] ?? null;

            if ($tool === null) {
                $this->components->error("No tool named [{$name}] is in the manifest.");

                return self::FAILURE;
            }

            $tools = [$name => $tool];
        }

        // The description is the whole of what an agent reads before it
        // decides, so it is shown as the agent gets it rather than summarised.
        foreach ($tools as $tool) {
            $this->components->twoColumnDetail("<options=bold>{$tool->name}</>", "<fg=gray>{$tool->target->describe()}</>");
            $this->line('  '.str_replace("\n", "\n  ", $tool->description));
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
