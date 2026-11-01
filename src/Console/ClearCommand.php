<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Manifest\ManifestRepository;

class ClearCommand extends Command
{
    protected $signature = 'mcp-bridge:clear';

    protected $description = 'Remove the compiled tool manifest';

    public function handle(ManifestRepository $manifest): int
    {
        $manifest->clear();

        $this->components->info('The compiled tool manifest has been removed.');

        return self::SUCCESS;
    }
}
