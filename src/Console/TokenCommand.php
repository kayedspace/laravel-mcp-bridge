<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Models\McpToken;
use KayedSpace\McpBridge\Support\Duration;

class TokenCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:token
        {user : The user to issue the token for, by id or by email}
        {--name= : What this token is for, shown when listing tokens}
        {--scopes= : Comma separated scopes; omit for every scope, which is *}
        {--client= : Which agent this is for, recorded for the audit}
        {--expires= : How long it lasts, such as 90d or 12h; omit for the configured default}';

    protected $description = 'Issue a token an agent can connect with';

    public function __construct(protected Repository $config)
    {
        parent::__construct();
    }

    public function handle(AuthFactory $auth): int
    {
        $user = $this->user($auth);

        if ($user === null) {
            $this->components->error('No user matched ['.$this->stringArgument('user').'].');

            return self::FAILURE;
        }

        if (! method_exists($user, 'createMcpToken')) {
            $this->components->error(
                $user::class.' cannot hold tokens. Add the '.
                'KayedSpace\McpBridge\Concerns\HasMcpTokens trait to it.',
            );

            return self::FAILURE;
        }

        $issued = McpToken::issue(
            tokenable: $user,
            name: $this->name(),
            scopes: $this->scopes(),
            expiresAt: $this->expiry(),
            client: $this->stringOption('client'),
        );

        $this->report($issued->accessToken, $issued->plainText);

        return self::SUCCESS;
    }

    protected function user(AuthFactory $auth): ?Model
    {
        $identifier = $this->stringArgument('user');
        $provider = $auth->guard()->getProvider();

        $user = str_contains($identifier, '@')
            ? $provider->retrieveByCredentials(['email' => $identifier])
            : $provider->retrieveById($identifier);

        return $user instanceof Model ? $user : null;
    }

    protected function name(): string
    {
        return $this->stringOption('name', 'Agent token') ?? 'Agent token';
    }

    /**
     * Omitted means every scope, spelled out as `*` on the token rather than
     * left as an empty list, so what the token holds is written down.
     *
     * @return list<string>
     */
    protected function scopes(): array
    {
        return $this->listOption('scopes', ['*']);
    }

    protected function expiry(): ?Carbon
    {
        $expires = $this->stringOption('expires')
            ?? $this->config->string('mcp-bridge.tokens.default_expiry', '90d');

        // A token that never expires is a decision, so it has to be spelled
        // out rather than reached by leaving the option off.
        if ($expires === 'never') {
            return null;
        }

        $seconds = Duration::seconds($expires, 0);

        return $seconds === 0 ? null : Date::now()->addSeconds($seconds);
    }

    /**
     * Printed once, with how to use it, because this is the only moment the
     * plain token exists.
     */
    protected function report(McpToken $token, string $plain): void
    {
        $this->components->info("Token [{$token->name}] issued. It is shown once.");

        $this->newLine();
        $this->line("  <options=bold>{$plain}</>");
        $this->newLine();

        $this->components->twoColumnDetail('Scopes', $token->holdsEveryScope() ? 'every scope (*)' : implode(', ', $token->scopes));
        $this->components->twoColumnDetail('Expires', $token->expires_at?->toDayDateTimeString() ?? 'never');

        if ($token->holdsEveryScope()) {
            $this->components->warn('This token holds every scope. Pass --scopes to narrow it to what the agent needs.');
        }

        $url = rtrim($this->config->string('app.url', 'http://localhost'), '/')
            .$this->config->string('mcp-bridge.servers.default.path', '/mcp');

        $this->newLine();
        $this->line('  Connect a client with:');
        $this->line("  <fg=gray>claude mcp add --transport http my-app {$url} --header \"Authorization: Bearer {$plain}\"</>");
    }
}
