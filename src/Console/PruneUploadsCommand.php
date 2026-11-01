<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Date;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Models\McpUpload;
use KayedSpace\McpBridge\Support\Duration;

class PruneUploadsCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:prune-uploads
        {--older-than= : How long an unused file is kept, such as 24h; defaults to uploads.ttl}';

    protected $description = 'Delete staged files that were never used';

    public function handle(FilesystemFactory $filesystem, Repository $config): int
    {
        $seconds = Duration::seconds(
            $this->stringOption('older-than') ?? $config->get('mcp-bridge.uploads.ttl'),
            86400,
        );

        $before = Date::now()->subSeconds($seconds);
        $deleted = 0;

        // The file goes with the row. A staging area that keeps bytes nobody
        // can reach any more is just a slow disk leak.
        foreach (McpUpload::query()->where('created_at', '<', $before)->cursor() as $upload) {
            $filesystem->disk($upload->disk)->delete($upload->path);
            $upload->delete();
            $deleted++;
        }

        $this->components->info("Deleted {$deleted} staged files older than {$before->diffForHumans()}.");

        return self::SUCCESS;
    }
}
