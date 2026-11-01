<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Lint\Finding;
use KayedSpace\McpBridge\Lint\Linter;

class LintCommand extends Command
{
    protected $signature = 'mcp-bridge:lint
        {--source= : Only candidates from this source, such as route or command}
        {--all : Include the deliberate choices: what is not opted in, and what is ignored}
        {--json : Machine-readable output, for CI}';

    protected $description = 'Check what this application would expose to an agent, before an agent does';

    public function handle(Linter $linter): int
    {
        $source = $this->option('source');

        $findings = $linter->lint(
            all: (bool) $this->option('all'),
            only: is_string($source) && $source !== '' ? $source : null,
        );

        return $this->option('json')
            ? $this->asJson($findings)
            : $this->asTable($findings);
    }

    /** @param list<Finding> $findings */
    protected function asJson(array $findings): int
    {
        $this->line((string) json_encode(
            array_map(fn (Finding $finding): array => $finding->toArray(), $findings),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return $this->exitCode($findings);
    }

    /** @param list<Finding> $findings */
    protected function asTable(array $findings): int
    {
        if ($findings === []) {
            $this->components->info('Nothing to report.');

            return self::SUCCESS;
        }

        $this->table(
            ['Level', 'Subject', 'What'],
            array_map(fn (Finding $finding): array => [
                $this->level($finding),
                $finding->subject,
                $finding->hint === null
                    ? $finding->message
                    : $finding->message."\n".$finding->hint,
            ], $findings),
        );

        return $this->summarise($findings);
    }

    /** @param list<Finding> $findings */
    protected function summarise(array $findings): int
    {
        $errors = count(array_filter($findings, fn (Finding $finding): bool => $finding->isError()));
        $others = count($findings) - $errors;

        if ($errors === 0) {
            $this->components->info("{$others} things to look at, nothing broken.");

            return self::SUCCESS;
        }

        $this->components->error("{$errors} errors and {$others} warnings.");

        return self::FAILURE;
    }

    /** @param list<Finding> $findings */
    protected function exitCode(array $findings): int
    {
        foreach ($findings as $finding) {
            if ($finding->isError()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    protected function level(Finding $finding): string
    {
        return match ($finding->level) {
            Finding::ERROR => '<fg=red>error</>',
            Finding::WARNING => '<fg=yellow>warning</>',
            default => '<fg=gray>note</>',
        };
    }
}
