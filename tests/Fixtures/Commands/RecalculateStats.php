<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Commands;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'Recalculate course statistics for a date range.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class RecalculateStats extends Command
{
    protected $signature = 'stats:recalculate
        {course : The course to recalculate}
        {--from= : Start date}
        {--until=}
        {--force : Ignore the cached figures}
        {--tag=* : Limit to these tags}';

    protected $description = 'Recalculate course statistics.';

    public function handle(): int
    {
        $this->line('course='.$this->argument('course'));
        $this->line('from='.($this->option('from') ?? ''));
        $this->line('force='.($this->option('force') ? 'yes' : 'no'));
        $this->line('tags='.implode(',', (array) $this->option('tag')));
        $this->line('actor='.(auth()->id() ?? 'none'));

        return self::SUCCESS;
    }
}
