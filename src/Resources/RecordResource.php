<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Resources;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Execution\ToolRunner;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Resources\Concerns\RequiresResourceScopes;
use KayedSpace\McpBridge\Security\ActorResolver;
use KayedSpace\McpBridge\Security\TokenScopes;
use KayedSpace\McpBridge\Server\ServerKeyResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

/**
 * One record, as a resource: `app://records/model_get_course/12`.
 *
 * A client that attaches resources to a conversation wants a record it got
 * an id for from a list as something it can hold onto, rather than a tool
 * it has to call again. This is the one resource template the package
 * serves, and it adds no authority of its own: the read goes through the
 * `get` model tool it names, through the runner, with the gate, the
 * policy, the shaping and the audit that tool would have had.
 */
class RecordResource extends Resource implements HasUriTemplate
{
    use RequiresResourceScopes;

    protected string $name = 'records';

    protected string $title = 'A record by id';

    protected string $description = 'One record, read through the get tool named in the uri: app://records/model_get_course/12.';

    protected string $mimeType = 'application/json';

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('app://records/{tool}/{id}');
    }

    public function handle(
        Request $request,
        ManifestRepository $manifest,
        ServerKeyResolver $keys,
        ToolRunner $runner,
        ActorResolver $actors,
        Repository $mcpConfig,
        TokenScopes $mcpScopes,
    ): Response {
        $refused = $this->refusalFor($request, $mcpConfig, $mcpScopes);

        if ($refused !== null) {
            return $refused;
        }

        $tool = $request->get('tool');
        $id = $request->get('id');
        $definition = is_string($tool) ? $manifest->find($tool) : null;
        $key = $definition === null ? null : $this->keyName($definition);

        // Only a get tool on this server, so the template reaches nothing a
        // tool on this endpoint could not, and nothing on another one.
        if ($definition === null || $key === null || ! is_scalar($id) || ! $this->readsRecords($definition, $keys->current())) {
            return Response::error('No record tool with that name is served here. Name a get tool, as in app://records/model_get_course/12.');
        }

        $actor = $actors->resolve($request->user());

        if ($actor === null) {
            return Response::error($actors->problem() ?? 'Not authenticated. The connection needs to be re-authorised.');
        }

        $result = $runner->run(new ToolCall(
            definition: $definition,
            arguments: [$key => $id],
            actor: $actor,
            requestId: (string) Str::uuid(),
            clientName: 'resource',
        ));

        if ($result->isError) {
            return Response::error($result->text);
        }

        return Response::json($result->structured['data'] ?? $result->text);
    }

    protected function readsRecords(ToolDefinition $definition, string $server): bool
    {
        return $definition->server === $server
            && $definition->source === 'model';
    }

    /** The input the get tool takes its record's key in. */
    protected function keyName(ToolDefinition $definition): ?string
    {
        foreach ($definition->input->fields as $field) {
            if (in_array('model:key', $field->origin, true)) {
                return $field->path;
            }
        }

        return null;
    }
}
