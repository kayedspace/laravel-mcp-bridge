<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Execution\ToolRunner;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Models\McpToken;
use KayedSpace\McpBridge\Support\Keys;

/**
 * Calls a tool from the shell, exactly as an agent would: the same
 * authorization, the same execution, the same shaping.
 *
 * The point is to answer "what does the agent actually get" without wiring up
 * a client, so it deliberately does not skip the gate. A destructive tool asks
 * for confirmation here too, and the handle it prints can be passed straight
 * back with --confirm.
 */
class CallCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:call
        {tool : The tool to call}
        {--arg=* : An argument, as name=value; repeat for several}
        {--json= : All arguments at once, as a JSON object}
        {--as= : The user to call as, by id or email; defaults to user 1}
        {--scopes= : Comma separated scopes the call holds; defaults to every scope, which is *}
        {--confirm= : A confirmation handle from a previous call}';

    protected $description = 'Call a tool the way an agent would, and print what it returns';

    public function handle(ManifestRepository $manifest, ToolRunner $runner, AuthFactory $auth): int
    {
        $definition = $manifest->find($this->stringArgument('tool'));

        if ($definition === null) {
            $this->components->error(
                'No tool named ['.$this->stringArgument('tool').']. Run [mcp-bridge:tools --refused] to see what was found and why it is not exposed.',
            );

            return self::FAILURE;
        }

        $actor = $this->actor($auth);

        if ($actor === null) {
            $this->components->error('No user to call as. Pass --as with an id or an email.');

            return self::FAILURE;
        }

        $this->holdScopes($actor);

        $call = new ToolCall(
            definition: $definition,
            arguments: $this->toolArguments(),
            actor: $actor,
            requestId: (string) Str::uuid(),
            clientName: 'mcp-bridge:call',
            confirm: $this->stringOption('confirm'),
        );

        // The same pipeline a connected client goes through, gate and audit
        // included, because the question this answers is what the agent gets.
        return $this->report($runner->run($call));
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $json = $this->stringOption('json');

        if ($json !== null) {
            $decoded = json_decode($json, true);

            if (! is_array($decoded)) {
                $this->components->warn('The --json value is not an object, so it was ignored.');

                return [];
            }

            return Keys::strings($decoded);
        }

        $arguments = [];

        // A repeatable option arrives as an array whose entries are null when
        // the flag was passed without a value.
        foreach ((array) $this->option('arg') as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);

            // name=value is all a shell gives us, so a dotted name builds the
            // nesting the schema asked for: filter.status=published.
            Arr::set($arguments, $name, $this->cast($value));
        }

        return Keys::strings($arguments);
    }

    protected function cast(string $value): mixed
    {
        return match (true) {
            $value === 'true' => true,
            $value === 'false' => false,
            $value === 'null' => null,
            is_numeric($value) => $value + 0,
            str_contains($value, ',') => array_map('trim', explode(',', $value)),
            default => $value,
        };
    }

    protected function actor(AuthFactory $auth): ?Authenticatable
    {
        $identifier = $this->stringOption('as');
        $provider = $auth->guard()->getProvider();

        if ($identifier === null) {
            return $provider->retrieveById(1);
        }

        return str_contains($identifier, '@')
            ? $provider->retrieveByCredentials(['email' => $identifier])
            : $provider->retrieveById($identifier);
    }

    /**
     * The scopes this call holds, carried by a token that is never saved.
     *
     * A user loaded from the database holds no token, and the scope check
     * fails closed, so without this every tool that declares a scope, which
     * is every tool outside the route source, refused the one command meant
     * for trying tools out. The token is attached the way the guard attaches
     * a real one, so what the gate sees is what it would see on a connection.
     */
    protected function holdScopes(Authenticatable $actor): void
    {
        if (! method_exists($actor, 'withMcpToken')) {
            $this->components->warn(
                $actor::class.' cannot hold a token, so a tool that declares a scope will refuse this call. '.
                'Add the HasMcpTokens trait to it.',
            );

            return;
        }

        $held = $this->listOption('scopes', ['*']);

        $token = new McpToken;
        $token->forceFill(['name' => 'mcp-bridge:call', 'scopes' => $held]);

        $actor->withMcpToken($token);

        // Who and with what, because the default actor is whoever user 1 is,
        // and on most applications that is the person with the most rights.
        $this->components->twoColumnDetail('Running as', $this->describe($actor));
        $this->components->twoColumnDetail('Scopes held', $held === [] ? 'none' : implode(', ', $held));
    }

    protected function describe(Authenticatable $actor): string
    {
        $id = $actor->getAuthIdentifier();
        $email = $actor instanceof Model ? $actor->getAttribute('email') : null;

        return class_basename($actor).'#'.(is_scalar($id) ? (string) $id : '?')
            .(is_string($email) && $email !== '' ? " ({$email})" : '');
    }

    protected function report(ToolResult $result): int
    {
        $this->newLine();

        if ($result->isError) {
            $this->components->error($result->errorCode ?? 'error');
        }

        $this->line($result->text);

        if ($result->structured !== null) {
            $this->newLine();
            $this->line((string) json_encode($result->structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $result->isError ? self::FAILURE : self::SUCCESS;
    }
}
