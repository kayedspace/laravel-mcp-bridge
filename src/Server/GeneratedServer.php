<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Server;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\McpBridgeManager;
use KayedSpace\McpBridge\Resources\RecordResource;
use KayedSpace\McpBridge\Resources\ResourceRegistry;
use KayedSpace\McpBridge\Support\Duration;
use KayedSpace\McpBridge\Uploads\Uploads;
use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolSearch;

/**
 * The server laravel/mcp serves, filled from the compiled manifest for
 * whichever configured endpoint the connection arrived on.
 */
class GeneratedServer extends Server
{
    /** @var array<string, Cacheable> */
    protected array $cacheHints = [];

    public function __construct(
        Transport $transport,
        ManifestRepository $manifest,
        ServerKeyResolver $keys,
        ResourceRegistry $resources,
        Instructions $instructions,
        Repository $config,
        Container $container,
    ) {
        parent::__construct($transport);

        $key = $keys->current();

        $this->name = $config->string("mcp-bridge.servers.{$key}.name", $config->string('app.name', 'Laravel'));
        $this->version = McpBridgeManager::version();

        // Always set, because laravel/mcp's default text describes a server
        // this is not. Empty when the application said to send none.
        $this->instructions = $instructions->for($key);

        $this->tools = array_map(
            fn (ToolDefinition $definition): GeneratedTool => $container->make(GeneratedTool::class, [
                'definition' => $definition,
            ]),
            array_values($manifest->forServer($key)),
        );

        $this->resources = $resources->forServer($this->sources($config, $key));

        // A record a list tool handed an id for, readable as a resource
        // through the get tool that serves it. Only where such a tool is.
        if ($resources->readsRecords() && $this->readsRecords($manifest, $key)) {
            $this->resources[] = RecordResource::class;
        }

        // Passed through rather than discovered. A prompt is written by hand
        // for a job an agent does repeatedly, and there is nothing in an
        // application that already is one, so the only sensible thing this
        // package can do is stop being in the way of registering them.
        $this->prompts = $this->classes($config, "mcp-bridge.servers.{$key}.prompts", Prompt::class);

        // An application's own laravel/mcp tools and resources, served as
        // they are beside the generated ones, so an agent needs one address
        // and one token rather than a server per way the tools were made.
        $this->resources = [...$this->resources, ...$this->classes($config, "mcp-bridge.servers.{$key}.resources", Resource::class)];

        foreach ($this->classes($config, "mcp-bridge.servers.{$key}.tools", Tool::class) as $class) {
            $tool = $container->make($class);

            if ($tool instanceof Tool) {
                $this->tools[] = $tool;
            }
        }

        // A tool an agent can see is a tool it will eventually try, so the
        // uploader is only there when something on this server takes a file.
        if ($config->boolean('mcp-bridge.uploads.enabled', true) && $this->acceptsUploads($manifest, $key)) {
            $this->tools[] = $container->make(UploadTool::class);
        }

        // A job tool answers "accepted", so a server that carries one owes
        // the agent a way to ask what happened next.
        if ($this->queuesJobs($manifest, $key)) {
            $this->tools[] = $container->make(JobStatusTool::class);
        }

        $this->tools = $this->searchable($config, $this->tools);

        $this->cacheHints = $this->hints($config, array_values($manifest->forServer($key)));
    }

    /**
     * Past a certain number, listing every tool stops helping: the client
     * spends its context on a catalog instead of the work, and an agent picks
     * worse from forty descriptions than from the five it searched for.
     *
     * laravel/mcp already implements this. Nesting the tools under its
     * `ToolSearch` key replaces the flat list with a search tool and an
     * execute tool, so the catalog is queried rather than recited.
     *
     * @param  list<Tool>  $tools
     * @return array<int|string, list<Tool>|Tool>
     */
    protected function searchable(Repository $config, array $tools): array
    {
        $threshold = $config->integer('mcp-bridge.tool_search_threshold', 40);

        return $threshold > 0 && count($tools) > $threshold
            ? [ToolSearch::class => $tools]
            : $tools;
    }

    /**
     * How long a client may reuse a list it already has.
     *
     * The 2026-07-28 revision lets a server say this, and a tool list that
     * changes only when the manifest is rebuilt is worth not re-fetching on
     * every connection.
     *
     * The scope is the part that matters. `tools/list` is filtered per caller,
     * so a shared cache would hand one caller's surface to another: a tool
     * hidden because the caller lacks its scope would arrive from a cache
     * filled by someone who had it. Shared is therefore downgraded to private
     * the moment any tool on this server declares a scope or an ability, no
     * matter what the configuration says.
     *
     * @param  list<ToolDefinition>  $tools
     * @return array<string, Cacheable>
     */
    protected function hints(Repository $config, array $tools): array
    {
        if (! $config->boolean('mcp-bridge.protocol.cache_hints.enabled', true)) {
            return [];
        }

        $ttl = Duration::seconds($config->get('mcp-bridge.protocol.cache_hints.ttl'), 300) * 1000;

        if ($ttl <= 0) {
            return [];
        }

        $cacheable = new Cacheable(ttlMs: $ttl, scope: $this->scope($config, $tools));

        return array_fill_keys([
            'server/discover',
            'tools/list',
            'prompts/list',
            'resources/list',
            'resources/templates/list',
        ], $cacheable);
    }

    /** @param list<ToolDefinition> $tools */
    protected function scope(Repository $config, array $tools): CacheScope
    {
        if ($config->string('mcp-bridge.protocol.cache_hints.scope', 'private') !== 'public') {
            return CacheScope::Private;
        }

        foreach ($tools as $tool) {
            if ($tool->authority->scopes !== [] || $tool->authority->abilities !== []) {
                return CacheScope::Private;
            }
        }

        return CacheScope::Public;
    }

    /** @return array<string, Cacheable> */
    protected function cacheHints(): array
    {
        return $this->cacheHints;
    }

    protected function readsRecords(ManifestRepository $manifest, string $key): bool
    {
        foreach ($manifest->forServer($key) as $definition) {
            if ($definition->source === 'model' && $definition->target instanceof ClassTarget && $definition->target->method === 'get') {
                return true;
            }
        }

        return false;
    }

    protected function queuesJobs(ManifestRepository $manifest, string $key): bool
    {
        foreach ($manifest->forServer($key) as $definition) {
            if ($definition->source === 'job') {
                return true;
            }
        }

        return false;
    }

    protected function acceptsUploads(ManifestRepository $manifest, string $key): bool
    {
        foreach ($manifest->forServer($key) as $definition) {
            if (Uploads::wanted($definition->input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Class names from a config list, keeping only the ones that are really
     * what the server expects.
     *
     * A stale entry left after a rename, or something that is not a prompt at
     * all, is dropped rather than taken on trust: the alternative is an
     * endpoint that fails to build, and a configuration typo should not be
     * able to take the whole surface down.
     *
     * @template T of object
     *
     * @param  class-string<T>  $type
     * @return list<class-string<T>>
     */
    protected function classes(Repository $config, string $key, string $type): array
    {
        $kept = [];

        foreach ($config->array($key, []) as $class) {
            if (is_string($class) && is_subclass_of($class, $type)) {
                $kept[] = $class;
            }
        }

        return $kept;
    }

    /**
     * @return list<string>
     */
    protected function sources(Repository $config, string $key): array
    {
        $sources = $config->array("mcp-bridge.servers.{$key}.sources", []);

        return array_values(array_filter($sources, 'is_string'));
    }
}
