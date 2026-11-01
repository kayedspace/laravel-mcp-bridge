<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Resources;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Resources\Concerns\RequiresResourceScopes;
use KayedSpace\McpBridge\Security\TokenScopes;
use KayedSpace\McpBridge\Server\ServerKeyResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/**
 * Every tool on this server and the shape of its input, in one read.
 *
 * `tools/list` already says this, but one document an agent can keep is
 * cheaper to reason over than a list it has to re-read, and it is the natural
 * place to say what each input is *for* rather than only its type.
 */
class SchemaResource extends Resource
{
    use RequiresResourceScopes;

    protected string $name = 'schema';

    protected string $title = 'Tool schema';

    protected string $description = 'Every tool on this server, what it targets, and what each input means.';

    protected string $uri = 'app://schema';

    protected string $mimeType = 'application/json';

    public function handle(Request $request, ManifestRepository $manifest, ServerKeyResolver $keys, Repository $mcpConfig, TokenScopes $mcpScopes): Response
    {
        $refused = $this->refusalFor($request, $mcpConfig, $mcpScopes);

        if ($refused !== null) {
            return $refused;
        }

        $tools = array_map(
            fn (ToolDefinition $definition): array => [
                'name' => $definition->name,
                'source' => $definition->source,
                'module' => $definition->module,
                'target' => $definition->target->describe(),
                'description' => $definition->description,
                'safety' => $definition->safety->toArray(),
                'scopes' => $definition->authority->scopes,
                'confirm' => $definition->authority->confirm,
                'input' => $this->input($definition),
            ],
            array_values($manifest->forServer($keys->current())),
        );

        return Response::json(['server' => $keys->current(), 'tools' => $tools]);
    }

    /**
     * Hidden fields are left out: they are not the agent's to send, so
     * describing them would only invite an attempt.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function input(ToolDefinition $definition): array
    {
        $fields = [];

        foreach ($definition->input->fields as $path => $field) {
            if ($field->hidden) {
                continue;
            }

            $fields[$path] = array_filter([
                'type' => $field->type->value,
                'required' => $field->required,
                'description' => $field->description,
                'enum' => $field->enum,
                'default' => $field->default,
                'examples' => $field->examples,
            ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false);
        }

        return $fields;
    }
}
