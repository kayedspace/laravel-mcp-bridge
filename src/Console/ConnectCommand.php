<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use KayedSpace\McpBridge\Console\Concerns\ReadsInput;
use KayedSpace\McpBridge\Server\Instructions;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The connection a client wants, printed ready to paste, or applied on this
 * machine.
 *
 * Every client spells the same three facts differently, and getting one of
 * them slightly wrong produces a silent failure rather than an error, so the
 * package writes them out rather than the team guessing. Printing is the
 * default because the client's configuration lives on the client's machine,
 * which in production is not the server's; `--apply` is for the case where
 * the two are one, which is every developer's laptop.
 */
class ConnectCommand extends Command
{
    use ReadsInput;

    protected $signature = 'mcp-bridge:connect
        {client=claude : claude, codex, gemini, qwen, copilot, cursor, vscode, opencode, windsurf, chatgpt, n8n or json}
        {--server=default : Which configured server to connect to}
        {--token= : The token to connect with}
        {--stdio : Run the application as a subprocess instead of connecting over HTTP}
        {--apply : Connect on this machine: run the client\'s mcp add command, or write its project file}
        {--skill : Also write the server\'s instructions as a Claude Code skill under .claude/skills}';

    protected $description = 'Print ready-to-paste setup for an MCP client, or connect one on this machine';

    /** Clients configured by a command of their own, by display name. */
    protected const COMMANDS = [
        'claude' => 'Claude Code',
        'codex' => 'Codex',
        'gemini' => 'Gemini CLI',
        'qwen' => 'Qwen Code',
        'copilot' => 'GitHub Copilot CLI',
    ];

    /** Clients configured by a file in the project: the file, and the key servers sit under. */
    protected const FILES = [
        'cursor' => ['.cursor/mcp.json', 'mcpServers'],
        'vscode' => ['.vscode/mcp.json', 'servers'],
        'opencode' => ['opencode.json', 'mcp'],
    ];

    /** Clients whose settings live outside the project, so the block is pasted there. */
    protected const PASTED = [
        'windsurf' => '~/.codeium/windsurf/mcp_config.json',
        'chatgpt' => 'the connector settings',
        'n8n' => 'the MCP Client node credentials',
        'json' => 'your client\'s MCP settings',
    ];

    public function handle(Repository $config, Filesystem $files): int
    {
        $client = strtolower($this->stringArgument('client', 'claude'));

        if (! isset(self::COMMANDS[$client]) && ! isset(self::FILES[$client]) && ! isset(self::PASTED[$client])) {
            $this->components->error("There is no client called [{$client}]. Use one of: "
                .implode(', ', array_keys([...self::COMMANDS, ...self::FILES, ...self::PASTED])).'.');

            return self::FAILURE;
        }
        $server = $this->stringOption('server', 'default') ?? 'default';
        $name = str_replace(' ', '-', strtolower($config->string('app.name', 'laravel')));
        $stdio = (bool) $this->option('stdio');
        $token = $this->stringOption('token');

        if ($this->option('apply') && ! $stdio && $token === null) {
            $this->components->error('Pass --token with the token to connect with. Issue one with [mcp-bridge:token].');

            return self::FAILURE;
        }

        $entry = $stdio
            ? $this->stdioEntry($server)
            : $this->httpEntry($config, $server, $token ?? 'mcpb_your_token_here');

        $this->components->info($stdio
            ? "Running the [{$server}] server over stdio"
            : "Connecting a client to the [{$server}] server at ".$this->text($entry, 'url'));
        $this->newLine();

        $exit = $this->option('apply')
            ? $this->apply($client, $name, $entry, $files)
            : $this->show($client, $name, $entry);

        $this->newLine();

        if ($this->option('skill')) {
            $this->writeSkill($name, $server, $files);
        }

        if ($stdio) {
            $this->stdioWarnings($config);
        } elseif (! $this->option('apply')) {
            $this->components->warn('Issue a token first with [mcp-bridge:token], and keep it out of version control.');
        }

        return $exit;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function show(string $client, string $name, array $entry): int
    {
        if (isset(self::COMMANDS[$client])) {
            $this->printCommand($client, $name, $entry);

            return self::SUCCESS;
        }

        $this->line('  Put this in <options=bold>'.$this->where($client).'</>:');
        $this->newLine();
        $this->line($this->block($client, $name, $entry));

        return self::SUCCESS;
    }

    /**
     * The same facts, done rather than printed: the client's own `mcp add`
     * through its binary on this machine, or its project file merged in
     * place so a server already listed there is kept.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function apply(string $client, string $name, array $entry, Filesystem $files): int
    {
        if (isset(self::COMMANDS[$client])) {
            return $this->runClient($client, $name, $entry);
        }

        if (! isset(self::FILES[$client])) {
            $this->components->error($this->label($client).' has no file on this machine to write. Paste this into '.$this->where($client).':');
            $this->newLine();
            $this->line($this->block($client, $name, $entry));

            return self::FAILURE;
        }

        [$file, $key] = self::FILES[$client];
        $path = $this->laravel->basePath($file);
        $existing = $files->exists($path) ? json_decode($files->get($path), true) : [];
        $existing = is_array($existing) ? $existing : [];
        $servers = is_array($existing[$key] ?? null) ? $existing[$key] : [];

        $servers[$name] = $this->shaped($client, $entry);
        $existing[$key] = $servers;

        if ($client === 'opencode') {
            $existing = ['$schema' => 'https://opencode.ai/config.json', ...$existing];
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->components->info("Written to [{$path}] as [{$name}]. It holds the token: keep the file out of version control.");

        return self::SUCCESS;
    }

    /**
     * The server's instructions as a skill file, for a team that wants them
     * in the repository beside the client configuration. The same text the
     * server sends at initialize, so there is one source and nothing here
     * can describe a tool the server does not have.
     */
    protected function writeSkill(string $name, string $server, Filesystem $files): void
    {
        $text = $this->laravel->make(Instructions::class)->for($server);
        $path = $this->laravel->basePath(".claude/skills/{$name}-mcp/SKILL.md");

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, implode("\n", [
            '---',
            "name: {$name}-mcp",
            'description: >',
            "  How to use the [{$name}] MCP server: its conventions, handshakes and",
            '  refusal codes. Use whenever calling its tools.',
            '---',
            '',
            "# The {$name} MCP server",
            '',
            $text,
            '',
        ]));

        $this->components->info("Skill written to [{$path}].");
    }

    /** @param array<string, mixed> $entry */
    protected function runClient(string $client, string $name, array $entry): int
    {
        $binary = (new ExecutableFinder)->find($client);

        if ($binary === null) {
            $this->components->error("The [{$client}] command is not on this machine's PATH. Run this where it is:");
            $this->newLine();
            $this->printCommand($client, $name, $entry);

            return self::FAILURE;
        }

        $process = new Process([$binary, ...array_slice($this->invocation($client, $name, $entry), 1)]);
        $process->run();

        $output = trim($process->getOutput().$process->getErrorOutput());

        if ($output !== '') {
            $this->line('  '.str_replace("\n", "\n  ", $output));
        }

        if (! $process->isSuccessful()) {
            $this->components->error("{$client} mcp add failed. The command it ran was:");
            $this->line('  '.$this->shell($this->invocation($client, $name, $entry)));

            return self::FAILURE;
        }

        $this->components->info("Connected [{$name}] in ".self::COMMANDS[$client].'.');

        if ($client === 'codex' && ($entry['type'] ?? null) === 'http') {
            $this->components->warn('Codex reads the token from ['.$this->tokenVariable($name).']. Export it in your shell profile:');
            $this->line('  export '.$this->tokenVariable($name).'='.$this->token($entry));
        }

        return self::SUCCESS;
    }

    /**
     * The `mcp add` invocation as it is printed. Codex takes the token from
     * an environment variable rather than a header, so the token never lands
     * in its configuration file, and the export is printed first.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function printCommand(string $client, string $name, array $entry): void
    {
        if ($client === 'codex' && ($entry['type'] ?? null) === 'http') {
            $this->line('  export '.$this->tokenVariable($name).'='.$this->token($entry));
        }

        $this->lines($this->wrapped($this->invocation($client, $name, $entry)));
    }

    /**
     * The invocation broken before each option, `--transport` aside, and
     * before the `--` that starts a command, so a long line reads as the
     * flags it is made of.
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    protected function wrapped(array $arguments): array
    {
        $lines = [[]];

        foreach ($arguments as $index => $argument) {
            $breaks = str_starts_with($argument, '--') && $argument !== '--transport'
                && ! ($index > 0 && $arguments[$index - 1] === '--');

            if ($breaks && $lines[array_key_last($lines)] !== []) {
                $lines[] = [];
            }

            $lines[array_key_last($lines)][] = $argument;
        }

        return array_map(fn (array $line): string => $this->shell($line), $lines);
    }

    /**
     * Each client's own spelling of the same connection, as the argument
     * list a process takes.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    protected function invocation(string $client, string $name, array $entry): array
    {
        if (($entry['type'] ?? null) === 'http') {
            $url = $this->text($entry, 'url');

            return $client === 'codex'
                ? ['codex', 'mcp', 'add', $name, '--url', $url, '--bearer-token-env-var', $this->tokenVariable($name)]
                : [$client, 'mcp', 'add', '--transport', 'http', $name, $url, '--header', $this->authorization($entry)];
        }

        $process = [$this->text($entry, 'command'), ...$this->args($entry)];

        return match ($client) {
            'claude' => ['claude', 'mcp', 'add', '--transport', 'stdio', $name, '--', ...$process],
            'gemini', 'qwen' => [$client, 'mcp', 'add', $name, ...$process],
            default => [$client, 'mcp', 'add', $name, '--', ...$process],
        };
    }

    /**
     * The entry in the shape a file-configured client reads: opencode calls
     * an HTTP server remote and wants its command as one list, and Windsurf
     * names the address serverUrl.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function shaped(string $client, array $entry): array
    {
        $http = ($entry['type'] ?? null) === 'http';

        return match ($client) {
            'opencode' => $http
                ? ['type' => 'remote', 'url' => $entry['url'], 'headers' => $entry['headers'], 'enabled' => true]
                : ['type' => 'local', 'command' => [$this->text($entry, 'command'), ...$this->args($entry)], 'enabled' => true],
            'windsurf' => $http
                ? ['serverUrl' => $entry['url'], 'headers' => $entry['headers']]
                : $entry,
            default => $entry,
        };
    }

    /** @param array<string, mixed> $entry */
    protected function block(string $client, string $name, array $entry): string
    {
        $key = self::FILES[$client][1] ?? 'mcpServers';

        return (string) json_encode([$key => [$name => $this->shaped($client, $entry)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    protected function args(array $entry): array
    {
        return is_array($entry['args'] ?? null)
            ? array_values(array_map(fn (mixed $arg): string => is_scalar($arg) ? (string) $arg : '', $entry['args']))
            : [];
    }

    /** @param list<string> $arguments */
    protected function shell(array $arguments): string
    {
        return implode(' ', array_map(
            fn (string $argument): string => preg_match('/^[A-Za-z0-9_\/:.=@-]+$/', $argument) === 1 ? $argument : '"'.$argument.'"',
            $arguments,
        ));
    }

    protected function tokenVariable(string $name): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name)).'_MCP_TOKEN';
    }

    /** @param array<string, mixed> $entry */
    protected function token(array $entry): string
    {
        return substr($this->authorization($entry), strlen('Authorization: Bearer '));
    }

    protected function label(string $client): string
    {
        return self::COMMANDS[$client] ?? ucfirst($client);
    }

    protected function where(string $client): string
    {
        return self::FILES[$client][0] ?? self::PASTED[$client] ?? 'the client\'s MCP settings';
    }

    /**
     * One console write per line, because a test matches an expected
     * substring against a single write, and so does a reader's eye.
     *
     * @param  list<string>  $lines
     */
    protected function lines(array $lines): void
    {
        foreach ($lines as $index => $line) {
            $this->line(($index === 0 ? '  ' : '    ').$line.($index < count($lines) - 1 ? ' \\' : ''));
        }
    }

    /** @param array<string, mixed> $entry */
    protected function text(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $entry */
    protected function authorization(array $entry): string
    {
        $headers = is_array($entry['headers'] ?? null) ? $entry['headers'] : [];
        $value = $headers['Authorization'] ?? null;

        return 'Authorization: '.(is_string($value) ? $value : '');
    }

    /** @return array<string, mixed> */
    protected function httpEntry(Repository $config, string $server, string $token): array
    {
        $path = $config->string("mcp-bridge.servers.{$server}.path", '/mcp');

        return [
            'type' => 'http',
            'url' => rtrim($config->string('app.url', 'http://localhost'), '/').$path,
            'headers' => ['Authorization' => "Bearer {$token}"],
        ];
    }

    /** @return array<string, mixed> */
    protected function stdioEntry(string $server): array
    {
        return [
            'command' => 'php',
            'args' => [$this->laravel->basePath('artisan'), 'mcp:start', $server],
        ];
    }

    /**
     * A subprocess carries no token and therefore no caller, which is why the
     * setup is only worth having beside the two settings that make it work.
     */
    protected function stdioWarnings(Repository $config): void
    {
        if (! $config->boolean('mcp-bridge.local', false)) {
            $this->components->warn('Set [mcp-bridge.local] to true, or there is no stdio server to start.');
        }

        if ($config->string('mcp-bridge.security.auth', 'passthrough') !== 'service-account'
            && $config->get('mcp-bridge.security.local_actor') === null) {
            $this->components->warn('Set [mcp-bridge.security.local_actor] to the user these calls run as: a subprocess carries no token.');
        }
    }
}
