<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Server;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Audit\Redactor;
use KayedSpace\McpBridge\Contracts\StoresAudit;
use KayedSpace\McpBridge\Data\AuditEntry;
use KayedSpace\McpBridge\Enums\Outcome;
use KayedSpace\McpBridge\Security\ActorResolver;
use KayedSpace\McpBridge\Server\Concerns\NamesClient;
use KayedSpace\McpBridge\Uploads\UploadStore;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * The first half of an upload: the agent sends a file once and gets an id to
 * pass to whichever tool wants it.
 *
 * It exists only on servers whose tools actually accept a file, because a
 * tool an agent can see is a tool it will eventually try.
 */
class UploadTool extends Tool
{
    use NamesClient;

    public function __construct(
        protected UploadStore $uploads,
        protected StoresAudit $audit,
        protected Redactor $redactor,
        protected ServerKeyResolver $keys,
        protected ActorResolver $actors,
    ) {}

    public function name(): string
    {
        return 'upload_file';
    }

    public function title(): string
    {
        return 'Upload a file';
    }

    public function description(): string
    {
        return 'Store a file and get an id for it. Pass that id to any tool input that asks for a file, '
            .'instead of sending the file itself.';
    }

    /** @return array<string, mixed> */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The file name, including its extension.')
                ->required(),
            'contents' => $schema->string()
                ->description('The file itself, base64 encoded.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // Resolved the same way a tool call resolves it. Staging as the
        // token holder while the call that claims the file runs as a service
        // account means the claim never matches and the upload silently
        // never arrives.
        $actor = $this->actors->resolve($request->user());

        if ($actor === null) {
            return Response::error('Not authenticated. The connection needs to be re-authorised.');
        }

        $name = $request->get('name');
        $contents = $request->get('contents');

        if (! is_string($name) || $name === '' || ! is_string($contents) || $contents === '') {
            return Response::error('Both [name] and [contents] are needed.');
        }

        try {
            $upload = $this->uploads->stage($name, $contents, $actor);
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage());
        }

        $this->record($upload->name, $upload->size, $actor, $this->clientName($request));

        return Response::make(Response::text(
            "Stored [{$upload->name}] as upload [{$upload->id}]. Pass that id as the file input.",
        ))->withStructuredContent([
            'upload_id' => $upload->id,
            'name' => $upload->name,
            'size' => $upload->size,
            'expires_at' => $upload->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Staging a file is something an agent did, so it belongs in the same
     * trail as everything else it did. This tool is added to the server
     * rather than compiled into the manifest, so it has no definition to run
     * through the normal path and writes its own entry.
     *
     * The file itself is never in the entry. `contents` is the whole upload
     * base64 encoded, and an audit trail that copies every byte an agent
     * sent is a second place to store the files, not a record of them.
     */
    protected function record(string $name, int $size, Authenticatable $actor, ?string $client): void
    {
        $id = $actor->getAuthIdentifier();

        $entry = new AuditEntry(
            tool: $this->name(),
            source: 'upload',
            // The server this tool was added to, not a guess. It is attached
            // to whichever servers accept a file, so an upload to the
            // operations surface said `default` and the trail disagreed with
            // the endpoint it happened on.
            server: $this->keys->current(),
            actorType: $actor::class,
            actorId: is_scalar($id) ? (string) $id : null,
            client: $client,
            requestId: (string) Str::uuid(),
            arguments: $this->redactor->mask(['name' => $name, 'size' => $size]),
            outcome: Outcome::Allowed,
        );

        $this->audit->record($entry);

        // No `ToolCalled`, deliberately. That event carries the `ToolCall`
        // that produced it, and an upload has no definition, no scopes and no
        // limits: handing a listener an invented one would be worse than the
        // gap. The trail records it, which is where "what did this agent do"
        // is answered, and a listener that needs uploads specifically can
        // decorate `UploadStore`.
    }
}
