<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Security\KillSwitch;

class KillCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:kill
        {tool? : The tool to switch off}
        {--source= : Switch off every tool from this source}
        {--server= : Switch off every tool on this server}
        {--all : Switch off the whole surface}
        {--revive : Turn back on what this command switched off}
        {--list : Show what is currently switched off}';

    protected $description = 'Switch a tool, a source, or the whole surface off without a deploy';

    public function handle(KillSwitch $kill): int
    {
        if ($this->option('list')) {
            return $this->show($kill);
        }

        $target = $this->target();

        if ($target === null) {
            $this->components->error('Name a tool, or pass --source, --server or --all.');

            return self::FAILURE;
        }

        if ($this->option('revive')) {
            $target === KillSwitch::ALL ? $kill->reviveAll() : $kill->revive($target);

            $this->components->info("[{$target}] is back on.");

            return self::SUCCESS;
        }

        $kill->kill($target);

        $this->components->warn("[{$target}] is switched off. It is not listed and it cannot be called.");

        return self::SUCCESS;
    }

    protected function show(KillSwitch $kill): int
    {
        $killed = $kill->killed();

        $killed === []
            ? $this->components->info('Nothing is switched off.')
            : $this->components->bulletList($killed);

        return self::SUCCESS;
    }

    protected function target(): ?string
    {
        if ($this->option('all')) {
            return KillSwitch::ALL;
        }

        $source = $this->stringOption('source');

        if ($source !== null) {
            return "source:{$source}";
        }

        $server = $this->stringOption('server');

        if ($server !== null) {
            return "server:{$server}";
        }

        $tool = $this->stringArgument('tool');

        return $tool === '' ? null : $tool;
    }
}
