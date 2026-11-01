<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;

/**
 * The first five minutes, in one command: publish the configuration, create
 * the tables, compile a manifest, and say what is left to do by hand.
 */
class InstallCommand extends Command
{
    protected $signature = 'mcp-bridge:install {--force : Overwrite the published configuration}';

    protected $description = 'Publish the configuration, migrate, and compile a first manifest';

    public function handle(): int
    {
        $this->call('vendor:publish', array_filter([
            '--tag' => 'mcp-bridge-config',
            '--force' => $this->option('force') ? true : null,
        ]));

        // Running install is the decision, so the production prompt would
        // only hang a deploy script that calls it.
        $this->call('migrate', ['--force' => true]);

        // Cached in production only: a file written here would be stale after
        // the very next attribute, and a manifest compiled on demand is what a
        // developer editing declarations wants.
        if ($this->laravel->isProduction()) {
            $this->call('mcp-bridge:cache');
        }

        $this->newLine();
        $this->components->info('Installed. Three things are yours to do:');

        $this->components->bulletList([
            'Add the KayedSpace\McpBridge\Concerns\HasMcpTokens trait to the user model agents connect as.',
            'Put #[McpTool] on the controller actions you want exposed. Tools compile on demand here; '
                .'in production run [mcp-bridge:cache] in your deploy script.',
            'Issue a token with [mcp-bridge:token you@example.com] and connect with [mcp-bridge:connect].',
        ]);

        $this->newLine();
        $this->line('  Run <options=bold>mcp-bridge:lint</> before you ship, and <options=bold>mcp-bridge:doctor</> if a client cannot connect.');

        return self::SUCCESS;
    }
}
