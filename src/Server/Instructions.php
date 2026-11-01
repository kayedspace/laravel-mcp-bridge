<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Server;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Execution\IdempotencyKeys;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Resources\ResourceRegistry;
use KayedSpace\McpBridge\Uploads\Uploads;
use ReflectionClass;

/**
 * What a client is told at initialize: this server's conventions, sized to
 * what it actually serves.
 *
 * The agent on the other end never installed anything and may be any
 * client, so the protocol's own channel is the one place every one of them
 * reads. Each paragraph is sent only when its subject is on the server,
 * because the text is paid for in every session and a handshake the agent
 * will never meet is noise it has to carry.
 */
class Instructions
{
    public function __construct(
        protected ManifestRepository $manifest,
        protected ResourceRegistry $resources,
        protected Repository $config,
    ) {}

    public function for(string $server): string
    {
        $configured = $this->config->get("mcp-bridge.servers.{$server}.instructions");

        if ($configured === false) {
            return '';
        }

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $definitions = array_values($this->manifest->forServer($server));

        return implode("\n\n", array_values(array_filter([
            $this->opening(),
            $this->external($definitions),
            $this->lists($definitions),
            $this->confirmation($definitions),
            $this->idempotency($definitions),
            $this->jobs($definitions),
            $this->uploads($definitions),
            $this->refusals(),
            $this->resources($server, $definitions),
        ])));
    }

    protected function opening(): string
    {
        $app = $this->config->string('app.name', 'Laravel');

        $actor = $this->config->string('mcp-bridge.security.auth', 'passthrough') === 'service-account'
            ? 'Every call runs as a service account this application configured, under that account\'s authorization, and is recorded.'
            : 'Every call runs as the owner of the token you connected with, under the same authorization that person would meet, and is recorded.';

        return "These tools are {$app}'s own API. {$actor} "
            .'An argument a tool does not declare is refused, and the refusal names the ones it accepts.';
    }

    /** @param list<ToolDefinition> $definitions */
    protected function external(array $definitions): ?string
    {
        foreach ($definitions as $definition) {
            if (in_array($definition->source, ['remote', 'openapi'], true)) {
                return 'Tools named after another server or an external API are re-exported from it. They run with this application\'s own credential for that service; your identity is not forwarded.';
            }
        }

        return null;
    }

    /** @param list<ToolDefinition> $definitions */
    protected function lists(array $definitions): ?string
    {
        foreach ($definitions as $definition) {
            foreach (['filter', 'sort', 'include', 'page', 'per_page'] as $input) {
                if ($definition->input->get($input) !== null) {
                    return 'Lists take filter, sort, include, page and per_page where their schema declares them. '
                        .'Narrow with filters before paging, and ask for include only when the related rows are needed.';
                }
            }
        }

        return null;
    }

    /** @param list<ToolDefinition> $definitions */
    protected function confirmation(array $definitions): ?string
    {
        foreach ($definitions as $definition) {
            if ($definition->safety->destructive) {
                return 'A destructive tool answers first with a preview and a confirmation handle, not by doing the work. '
                    .'Show the preview to the user; to proceed, send the same call again with confirm set to the handle. '
                    .'A handle is single use and short-lived.';
            }
        }

        return null;
    }

    /** @param list<ToolDefinition> $definitions */
    protected function idempotency(array $definitions): ?string
    {
        foreach ($definitions as $definition) {
            if ($definition->input->get(IdempotencyKeys::INPUT) !== null) {
                return 'Write tools take '.IdempotencyKeys::INPUT.'. Send the same key when retrying a call whose answer you did not see, '
                    .'and the first answer comes back instead of the work being done twice.';
            }
        }

        return null;
    }

    /** @param list<ToolDefinition> $definitions */
    protected function jobs(array $definitions): ?string
    {
        foreach ($definitions as $definition) {
            if ($definition->source === 'job') {
                return 'A queued tool answers accepted with a status id rather than a result. Ask job_status with that id for the outcome.';
            }
        }

        return null;
    }

    /** @param list<ToolDefinition> $definitions */
    protected function uploads(array $definitions): ?string
    {
        if (! $this->config->boolean('mcp-bridge.uploads.enabled', true)) {
            return null;
        }

        foreach ($definitions as $definition) {
            if (Uploads::wanted($definition->input)) {
                return 'Where a tool takes a file, call upload_file first and pass the reference it returns in the file argument.';
            }
        }

        return null;
    }

    protected function refusals(): string
    {
        return 'A refusal names its code. MCP_BRIDGE_SCOPE: the token lacks the scope named; ask its owner for one. '
            .'MCP_BRIDGE_FORBIDDEN and MCP_BRIDGE_DISABLED: final, do not retry. '
            .'MCP_BRIDGE_RATE_LIMITED: wait the seconds given. '
            .'MCP_BRIDGE_VALIDATION: correct the fields listed and call again.';
    }

    /** @param list<ToolDefinition> $definitions */
    protected function resources(string $server, array $definitions): ?string
    {
        $sources = array_values(array_filter($this->config->array("mcp-bridge.servers.{$server}.sources", []), 'is_string'));
        $lines = [];

        foreach ($this->resources->forServer($sources) as $class) {
            // Default property values, so a resource is described without
            // being built: a resource may need the runner to answer, and
            // this only needs to know what it is.
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $uri = $defaults['uri'] ?? null;
            $description = $defaults['description'] ?? null;

            if (is_string($uri) && is_string($description)) {
                $lines[] = "{$uri}: {$description}";
            }
        }

        if ($this->resources->readsRecords() && $this->readsRecords($definitions)) {
            $lines[] = 'app://records/{tool}/{id}: one record, read through the get tool named in the uri.';
        }

        return $lines === [] ? null : "Resources you can read:\n".implode("\n", $lines);
    }

    /** @param list<ToolDefinition> $definitions */
    protected function readsRecords(array $definitions): bool
    {
        foreach ($definitions as $definition) {
            if ($definition->source === 'model' && $definition->target instanceof ClassTarget && $definition->target->method === 'get') {
                return true;
            }
        }

        return false;
    }
}
