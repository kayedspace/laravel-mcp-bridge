<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Models\McpToken;

class TokensCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:tokens
        {--revoke= : Revoke the token with this id}
        {--all : Include the revoked and expired ones}';

    protected $description = 'List the tokens agents connect with, and revoke one';

    public function handle(): int
    {
        // An integer, because the id is one, and a database that is strict
        // about types says so with an exception rather than an empty answer.
        if ($this->option('revoke') !== null) {
            return $this->revoke($this->intOption('revoke', 0));
        }

        $tokens = McpToken::query()
            ->when(! $this->option('all'), fn ($query) => $query->whereNull('revoked_at'))
            ->orderByDesc('id')
            ->get();

        if ($tokens->isEmpty()) {
            $this->components->info('No tokens have been issued.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Name', 'User', 'Scopes', 'Client', 'Last used', 'State'],
            $tokens->map(fn (McpToken $token): array => [
                $token->id,
                $token->name,
                $this->owner($token),
                $token->holdsEveryScope() ? 'every scope (*)' : implode(', ', $token->scopes),
                $token->client ?? '',
                $token->last_used_at?->diffForHumans() ?? 'never',
                $this->state($token),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /** Which record the token belongs to, as something readable in a table. */
    protected function owner(McpToken $token): string
    {
        $type = $token->getAttribute('tokenable_type');
        $id = $token->getAttribute('tokenable_id');

        return (is_string($type) ? class_basename($type) : 'unknown')
            .'#'.(is_scalar($id) ? (string) $id : '?');
    }

    protected function revoke(int $id): int
    {
        $token = McpToken::query()->find($id);

        if ($token === null) {
            $this->components->error("No token with id [{$id}].");

            return self::FAILURE;
        }

        $token->revoke();

        $this->components->info("Token [{$token->name}] is revoked. The agent using it will be refused immediately.");

        return self::SUCCESS;
    }

    protected function state(McpToken $token): string
    {
        return match (true) {
            $token->revoked_at !== null => '<fg=red>revoked</>',
            $token->hasExpired() => '<fg=yellow>expired</>',
            default => '<fg=green>active</>',
        };
    }
}
