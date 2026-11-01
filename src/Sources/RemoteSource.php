<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Contracts\ConnectsRemoteServers;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\Declared;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\RemoteTarget;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Execution\RemoteExecutor;
use KayedSpace\McpBridge\Schema\JsonSchemaReader;
use KayedSpace\McpBridge\Support\Keys;
use Throwable;

/**
 * Tools from another MCP server, re-exported through this one.
 *
 * The point is one endpoint and one token for an agent that would otherwise
 * hold several. The remote server's own schema is used as it stands, because
 * it is already a declaration; nothing here infers anything.
 *
 * Authority is this application's, not the remote's: a re-exported tool must
 * declare a scope and an ability here, and the token the remote is reached
 * with belongs to this application. An agent that may call our `list_invoices`
 * never learns the credential behind it.
 */
class RemoteSource implements Source
{
    public function __construct(
        protected ConnectsRemoteServers $servers,
        protected JsonSchemaReader $schemas,
        protected RemoteExecutor $executor,
        protected Repository $config,
    ) {}

    public function key(): string
    {
        return 'remote';
    }

    public function discover(): iterable
    {
        $candidates = [];

        foreach ($this->servers->servers() as $server) {
            foreach ($this->toolsOf($server) as $tool) {
                $candidate = $this->candidate($server, $tool);

                if ($candidate !== null) {
                    $candidates[$candidate->id] = $candidate;
                }
            }
        }

        ksort($candidates);

        return array_values($candidates);
    }

    public function schema(ToolCandidate $candidate): InputSchema
    {
        $schema = $candidate->meta['input_schema'] ?? null;

        return is_array($schema)
            ? $this->schemas->read(Keys::strings($schema), WireIn::Body)
            : InputSchema::empty();
    }

    public function validate(ToolCandidate $candidate): array
    {
        if ($candidate->meta['ignored'] ?? false) {
            return [Refusal::ignored($candidate->id)];
        }

        return [];
    }

    public function execute(ToolCall $call): SourceResult
    {
        return $this->executor->execute($call);
    }

    /**
     * A remote that cannot be reached at compile time yields no tools rather
     * than failing the build: the rest of the surface is still correct, and
     * `mcp-bridge:doctor` is where a connection problem belongs.
     *
     * @return list<array<string, mixed>>
     */
    protected function toolsOf(string $server): array
    {
        try {
            return $this->servers->tools($server);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $tool */
    protected function candidate(string $server, array $tool): ?ToolCandidate
    {
        $name = $tool['name'] ?? null;

        if (! is_string($name) || $name === '' || ! $this->allowed($server, $name)) {
            return null;
        }

        $settings = $this->config->array("mcp-bridge.sources.remote.servers.{$server}", []);
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];

        return new ToolCandidate(
            source: $this->key(),
            id: "remote:{$server}:{$name}",
            target: new RemoteTarget($server, $name),
            suggestedName: $this->name($server, $name, $settings),
            title: is_string($tool['title'] ?? null) ? $tool['title'] : null,
            description: $this->describe($server, $tool),
            groups: [$server],
            safety: $this->safety($annotations),
            declarations: [],
            meta: [
                'opted_in' => true,
                'ignored' => false,
                'server' => $server,
                'remote_tool' => $name,
                'input_schema' => $tool['inputSchema'] ?? [],
            ],
            declared: $this->declared($settings),
        );
    }

    /**
     * Authority comes from this application's configuration for the server,
     * since a remote tool has no attributes here to carry it.
     *
     * @param  array<array-key, mixed>  $settings
     */
    protected function declared(array $settings): Declared
    {
        return new Declared(
            scopes: $this->strings($settings['scopes'] ?? null),
            abilities: $this->strings($settings['abilities'] ?? null),
        );
    }

    /** @param array<array-key, mixed> $settings */
    protected function name(string $server, string $name, array $settings): string
    {
        $prefix = $settings['prefix'] ?? $server;

        // Prefixed by default: two remotes with a `list_invoices` each is the
        // normal case, not the exception.
        return is_string($prefix) && $prefix !== ''
            ? Str::snake($prefix).'_'.$name
            : $name;
    }

    /**
     * How much of a remote's description is repeated to our agent. A
     * description is model input, and a remote that wanted to steer an agent
     * would do it there; a cap does not stop that, but it bounds how much of
     * our tool list a remote gets to write.
     */
    protected const DESCRIPTION_LIMIT = 1000;

    /** @param array<string, mixed> $tool */
    protected function describe(string $server, array $tool): string
    {
        $description = $tool['description'] ?? null;
        $own = is_string($description) && $description !== '' ? $description : 'A tool from another server.';

        return rtrim(Str::limit($own, self::DESCRIPTION_LIMIT, ''), '. ').". Runs on the [{$server}] server.";
    }

    /**
     * The remote's own annotations, trusted as hints and nothing more: they
     * describe its tool, and this application is the one being asked to
     * confirm.
     *
     * @param  array<array-key, mixed>  $annotations
     */
    protected function safety(array $annotations): Safety
    {
        return new Safety(
            readOnly: ($annotations['readOnlyHint'] ?? false) === true,
            destructive: ($annotations['destructiveHint'] ?? true) === true,
            idempotent: ($annotations['idempotentHint'] ?? false) === true,
            openWorld: ($annotations['openWorldHint'] ?? true) === true,
        );
    }

    /**
     * `only` is an allowlist: `*` re-exports every tool the remote offers,
     * which a block that leaves it out gets, and an empty list re-exports
     * none. `except` then removes from whatever `only` allowed.
     */
    protected function allowed(string $server, string $name): bool
    {
        $settings = $this->config->array("mcp-bridge.sources.remote.servers.{$server}", []);

        $only = array_key_exists('only', $settings) ? $this->strings($settings['only']) : ['*'];
        $except = $this->strings($settings['except'] ?? null);

        if (! in_array('*', $only, true) && ! in_array($name, $only, true)) {
            return false;
        }

        return ! in_array($name, $except, true);
    }

    /** @return list<string> */
    protected function strings(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, 'is_string'))
            : [];
    }
}
