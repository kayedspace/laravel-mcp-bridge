<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Doctor\Checkup;

/**
 * The checkup, printed. The checks themselves live in `Checkup`, which
 * `laravel/doctor` reads too when it is installed, so `php artisan doctor`
 * and this command cannot disagree.
 */
class DoctorCommand extends Command
{
    protected $signature = 'mcp-bridge:doctor';

    protected $description = 'Check the configuration, guards, limiters, manifest and tables';

    public function handle(Checkup $checkup): int
    {
        $checks = $checkup->all();

        foreach ($checks as $check) {
            $this->components->twoColumnDetail(
                $check->label.' <fg=gray>'.$check->detail.'</>',
                $check->ok ? '<fg=green>ok</>' : '<fg=red>look at this</>',
            );
        }

        $failed = array_filter($checks, fn ($check): bool => ! $check->ok);

        $this->newLine();

        if ($failed === []) {
            $this->components->info('Everything checks out.');

            return self::SUCCESS;
        }

        $this->components->error(count($failed) === 1 ? '1 thing needs attention.' : count($failed).' things need attention.');

        return self::FAILURE;
    }
}
