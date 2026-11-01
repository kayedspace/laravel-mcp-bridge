<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Resources;

use Illuminate\Config\Repository;
use Laravel\Mcp\Server\Resource;

/**
 * Which app resources a server offers.
 *
 * Resources are reads of the application itself rather than tools, so they
 * are listed by name in `sources.resource.expose`: `routes` and `schema` are
 * on by default because they only describe what an agent can already reach,
 * while `schedule` and `config` say something about the application's
 * behaviour and are opt-in.
 */
class ResourceRegistry
{
    /** @var array<string, class-string<\Laravel\Mcp\Server\Resource>> */
    protected const RESOURCES = [
        'routes' => RoutesResource::class,
        'schema' => SchemaResource::class,
        'schedule' => ScheduleResource::class,
        'config' => ConfigResource::class,
    ];

    public function __construct(protected Repository $config) {}

    /**
     * @param  list<string>  $sources  the sources this server serves
     * @return list<class-string<\Laravel\Mcp\Server\Resource>>
     */
    public function forServer(array $sources): array
    {
        if (! in_array('resource', $sources, true)) {
            return [];
        }

        if (! $this->config->boolean('mcp-bridge.sources.resource.enabled', true)) {
            return [];
        }

        $resources = [];

        foreach ($this->exposed() as $name) {
            $resource = self::RESOURCES[$name] ?? null;

            if ($resource !== null) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    /**
     * Whether the record template is served, which is decided per server by
     * whether a model `get` tool is there to read through: a template on a
     * server with nothing behind it would only answer "no such tool".
     */
    public function readsRecords(): bool
    {
        if (! $this->config->boolean('mcp-bridge.sources.resource.enabled', true)) {
            return false;
        }

        $exposed = array_values(array_filter(
            $this->config->array('mcp-bridge.sources.resource.expose', ['routes', 'schema']),
            'is_string',
        ));

        return in_array('*', $exposed, true) || in_array('records', $exposed, true);
    }

    /**
     * `*` is all four, and an empty list is none. A list that quietly meant
     * "the default pair" was a setting that exposed the most when it said
     * the least.
     *
     * @return list<string>
     */
    protected function exposed(): array
    {
        $exposed = array_values(array_filter(
            $this->config->array('mcp-bridge.sources.resource.expose', ['routes', 'schema']),
            'is_string',
        ));

        return in_array('*', $exposed, true) ? array_keys(self::RESOURCES) : $exposed;
    }
}
