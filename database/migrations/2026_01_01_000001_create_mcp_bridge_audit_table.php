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
            $table->id();
            $table->string('tool');
            $table->string('source');
            $table->string('server');
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();

            // Which of the actor's tokens made the call. One person's Claude
            // and n8n tokens look alike by actor, and revoking the right one
            // starts with knowing which one did what. The type says which
            // table the id belongs to: a package token and a Sanctum token
            // can share an id, and must never be read as each other.
            $table->string('token_type')->nullable();
            $table->string('token_id')->nullable();
            $table->string('client')->nullable();
            $table->string('request_id');
            $table->json('arguments');
            $table->string('outcome');
            $table->string('error_code')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('confirmed')->default(false);
            $table->timestamp('created_at')->nullable();

            // The questions the trail is for: what did this tool do, what did
            // this actor do, what did this token do, and how many refusals
            // in the last hour.
            $table->index(['tool', 'created_at']);
            $table->index(['actor_type', 'actor_id']);
            $table->index(['token_type', 'token_id']);
            $table->index(['outcome', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config()->string('mcp-bridge.database.table_prefix', 'mcp_bridge_').'audit';
    }

    protected function connection(): ?string
    {
        $connection = config('mcp-bridge.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
};
