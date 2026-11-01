<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use KayedSpace\McpBridge\Models\McpToken;
use Workbench\App\Models\Course;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'agent@example.test'],
            UserFactoryAttributes::for('Agent Operator'),
        );

        Course::factory()->count(25)->create();

        // A known token, so the workbench is something you can point a client
        // at without issuing one first. It only exists in this demo app.
        $plain = McpToken::issue($user, 'Workbench', ['courses:read', 'courses:write'])->plainText;

        $this->command?->newLine();
        $this->command?->info('Workbench ready. Connect a client with:');
        $this->command?->line('  Authorization: Bearer '.$plain);
        $this->command?->newLine();
    }
}
