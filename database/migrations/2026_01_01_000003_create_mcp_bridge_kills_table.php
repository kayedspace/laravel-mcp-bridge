<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            // A tool name, `source:command`, `server:ops` or `*`. In a table
            // rather than the cache, because a kill switch that `cache:clear`
            // turns back on is not a switch.
            $table->string('target')->primary();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config()->string('mcp-bridge.database.table_prefix', 'mcp_bridge_').'kills';
    }

    protected function connection(): ?string
    {
        $connection = config('mcp-bridge.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
};
