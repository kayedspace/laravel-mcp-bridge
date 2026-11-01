<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Models\McpAuditEntry;

class PruneAuditCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:prune-audit
        {--older-than= : How long to keep, such as 90d; defaults to audit.retention_days}';

    protected $description = 'Delete audit entries older than the retention window';

    public function __construct(protected Repository $config)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = $this->days();
        $before = Date::now()->subDays($days);

        $deleted = McpAuditEntry::query()->where('created_at', '<', $before)->delete();
        $count = is_int($deleted) ? $deleted : 0;

        $this->components->info("Deleted {$count} audit entries older than {$days} days.");

        return self::SUCCESS;
    }

    protected function days(): int
    {
        $window = $this->stringOption('older-than');

        if ($window !== null && preg_match('/^(\d+)\s*d?$/', $window, $matches) === 1) {
            return (int) $matches[1];
        }

        return max($this->config->integer('mcp-bridge.audit.retention_days', 90), 1);
    }
}
