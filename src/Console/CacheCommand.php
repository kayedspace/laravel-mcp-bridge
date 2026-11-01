<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Manifest\ManifestRepository;

class CacheCommand extends Command
{
    protected $signature = 'mcp-bridge:cache';

    protected $description = 'Compile the tool manifest from every enabled source';

    public function handle(ManifestCompiler $compiler, ManifestRepository $manifest): int
    {
        $result = $compiler->compile();

        $manifest->write($result->definitions);

        $this->components->info("Compiled {$result->count()} tools to [{$manifest->path()}].");

        $refused = count($result->refusals);

        if ($refused > 0) {
            $this->components->warn(
                "{$refused} candidates were not exposed. Run [mcp-bridge:tools --refused] to see why.",
            );
        }

        return self::SUCCESS;
    }
}
