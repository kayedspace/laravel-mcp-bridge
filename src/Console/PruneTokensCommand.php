<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Models\McpToken;

class PruneTokensCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:prune-tokens
        {--days=30 : How long a revoked or expired token is kept for the audit trail}';

    protected $description = 'Delete the tokens that expired or were revoked long enough ago';

    public function handle(): int
    {
        $days = max($this->intOption('days', 30), 0);
        $before = Date::now()->subDays($days);

        // Kept for a while after they stop working, because an audit entry
        // that names a token nobody can look up explains less.
        $deleted = McpToken::query()
            ->where(fn ($query) => $query
                ->where('revoked_at', '<', $before)
                ->orWhere('expires_at', '<', $before))
            ->delete();

        $count = is_int($deleted) ? $deleted : 0;

        $this->components->info("Deleted {$count} tokens that stopped working before {$before->toDateString()}.");

        return self::SUCCESS;
    }
}
