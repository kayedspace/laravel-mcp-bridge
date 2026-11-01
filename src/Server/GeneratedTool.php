<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Server;

use Fiber;
use Generator;
use Illuminate\Config\Repository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Contracts\AuthorizesTools;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Execution\IdempotencyKeys;
use KayedSpace\McpBridge\Execution\StreamedOutput;
use KayedSpace\McpBridge\Execution\ToolRunner;
use KayedSpace\McpBridge\Output\Pagination;
use KayedSpace\McpBridge\Schema\JsonSchemaCompiler;
use KayedSpace\McpBridge\Security\ActorResolver;
use KayedSpace\McpBridge\Server\Concerns\NamesClient;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

/**
 * One compiled definition presented to laravel/mcp as a tool.
 *
 * There is deliberately no generated class per route: ServerContext accepts
 * tool instances, so a definition plus this class is enough.
 */
class GeneratedTool extends Tool
{
    use NamesClient;

    public function __construct(
        protected ToolDefinition $definition,
        protected JsonSchemaCompiler $compiler,
        protected AuthorizesTools $gate,
        protected ToolRunner $runner,
        protected ActorResolver $actors,
        protected Repository $config,
    ) {}

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function name(): string
    {
        return $this->definition->name;
    }

    public function title(): string
    {
        return $this->definition->title ?? Str::headline($this->definition->name);
    }

    public function description(): string
    {
        return $this->definition->description;
    }

    /**
     * What the response will actually contain, where that is known rather
     * than guessed.
     *
     * The envelope is this package's own code, so it can be stated exactly:
     * `data`, plus `pagination` whose keys the shaper controls. Declared
     * output fields name the keys of each record, because the shaper keeps
     * only those and drops the rest.
     *
     * Per-field types are never derived here. That means reading an API
     * Resource's `toArray()` to see what it builds, which is the whole job of
     * dedoc/scramble's inference engine and not worth reimplementing; where
     * the application turns that inference on, the record arrives typed on
     * the definition. Naming the keys without claiming their types is the
     * part that is true either way.
     *
     * Nothing is advertised unless a record shape is certain, because a
     * declared output schema tells a client to expect structured content, and
     * a tool whose route answers 204 would then be promising something it
     * does not send.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $fields = $this->definition->output->fields ?? [];
        $typed = $this->definition->output?->schema;
        $paginates = $this->paginates();

        if ($fields === [] && $typed === null && ! $paginates) {
            return [];
        }

        // Typed where API documentation inferred the record, narrowed to the
        // declared fields when both exist, since the shaper keeps only those.
        $record = $typed === null
            ? $schema->object()
            : $schema->object($this->compiler->compile($this->selected($typed, $fields), $schema));

        if ($fields !== [] && $typed === null) {
            $record = $record->description('Each record has: '.implode(', ', $fields).'.');
        }

        $properties = [
            'data' => $paginates ? $schema->array()->items($record) : $record,
        ];

        // Through the same compiler as every input, so the paging keys are
        // described in one place and typed by one piece of code.
        $pagination = $paginates
            ? $this->compiler->compile(InputSchema::empty()->with(...Pagination::fields()), $schema)
            : [];

        if (isset($pagination['pagination'])) {
            $properties['pagination'] = $pagination['pagination'];
        }

        return $properties;
    }

    /**
     * The typed record narrowed to the keys `#[McpOutput(fields:)]` keeps,
     * matched on the first segment so a kept `author` keeps `author.name`.
     *
     * @param  list<string>  $fields
     */
    protected function selected(InputSchema $typed, array $fields): InputSchema
    {
        if ($fields === []) {
            return $typed;
        }

        $kept = [];

        foreach ($typed->fields as $path => $field) {
            if (in_array(Str::before($path, '.'), $fields, true)) {
                $kept[$path] = $field;
            }
        }

        return new InputSchema($kept);
    }

    /**
     * A list endpoint, which is the case the shaper adds paging to.
     *
     * By the input's name rather than by which layer declared it, because an
     * endpoint pages whether `per_page` arrived from a Spatie query, a
     * validation rule or an attribute, and the shaper reads the response by
     * name too.
     */
    protected function paginates(): bool
    {
        foreach ($this->definition->input->fields as $field) {
            if (in_array($field->path, Pagination::INPUTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All four hints, always explicit: a client reads a missing hint as its
     * worst case.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => $this->definition->safety->readOnly,
            'destructiveHint' => $this->definition->safety->destructive,
            'idempotentHint' => $this->definition->safety->idempotent,
            'openWorldHint' => $this->definition->safety->openWorld,
        ];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->compiler->compile($this->definition->input, $schema);
    }

    /**
     * A tool whose scopes or abilities the caller lacks is not listed at all.
     *
     * Judged against the same actor the call will run as, which under
     * `service-account` is not the token holder. Listing by one subject and
     * calling as another would mean the two halves of "checked when listing
     * and again when calling" answered about different people, and a tool
     * could be listed for a caller the gate then refuses, or hidden from one
     * it would have allowed.
     */
    public function shouldRegister(Request $request): bool
    {
        return $this->gate->visible($this->definition, $this->actors->resolve($request->user()));
    }

    public function handle(Request $request): Response|ResponseFactory|Generator
    {
        $actor = $this->actors->resolve($request->user());

        // There is no null-actor path: a tool that cannot name who is calling
        // does not run. A misconfigured service account says so, rather than
        // reporting an authentication problem the caller cannot fix.
        if ($actor === null) {
            return Response::error(
                $this->actors->problem() ?? 'Not authenticated. The connection needs to be re-authorised.',
            );
        }

        $arguments = $request->all();

        // The confirmation handle is an input the agent sees but the code
        // behind the tool never does.
        $confirm = $arguments['confirm'] ?? null;
        $idempotencyKey = $arguments[IdempotencyKeys::INPUT] ?? null;
        unset($arguments['confirm'], $arguments[IdempotencyKeys::INPUT]);

        $call = new ToolCall(
            definition: $this->definition,
            arguments: $arguments,
            actor: $actor,
            requestId: (string) Str::uuid(),
            clientName: $this->clientName($request),
            confirm: is_string($confirm) ? $confirm : null,
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        $token = $this->progressToken($request);

        return $token === null
            ? $this->respond($this->runner->run($call))
            : $this->streamed($call, $token);
    }

    /**
     * A command's output, line by line, while it runs.
     *
     * The runner is synchronous, so it runs inside a fiber that the
     * command's output suspends on every line. Each suspension becomes a
     * progress notification, carrying the token the client sent, and the
     * fiber's return value is the result the call would have had anyway: the
     * same gate, the same audit, the same shaping, with the waiting filled in.
     *
     * @return Generator<int, Response|ResponseFactory>
     */
    protected function streamed(ToolCall $call, string|int $token): Generator
    {
        $fiber = new Fiber(fn (): ToolResult => $this->runner->run($call));
        $progress = 0;

        StreamedOutput::stream($fiber);

        try {
            $line = $fiber->start();

            while ($fiber->isSuspended()) {
                if (is_string($line)) {
                    yield Response::notification('notifications/progress', [
                        'progressToken' => $token,
                        'progress' => ++$progress,
                        'message' => $line,
                    ]);
                }

                $line = $fiber->resume();
            }
        } finally {
            StreamedOutput::stream(null);
        }

        $result = $fiber->getReturn();

        if (! $result instanceof ToolResult) {
            throw new RuntimeException('The command tool finished without a result.');
        }

        yield $this->respond($result);
    }

    /**
     * The token a client sends when it wants progress, which is the only
     * case the protocol allows progress to be sent. Only a command has
     * progress worth streaming: a route answers when it answers, and a job
     * is tracked by `job_status`.
     */
    protected function progressToken(Request $request): string|int|null
    {
        if ($this->definition->source !== 'command'
            || ! $this->config->boolean('mcp-bridge.sources.command.stream_output', true)) {
            return null;
        }

        $token = $request->meta()['progressToken'] ?? null;

        return is_string($token) || is_int($token) ? $token : null;
    }

    protected function respond(ToolResult $result): Response|ResponseFactory
    {
        if ($result->isError) {
            return Response::error($result->text);
        }

        if ($result->structured === null) {
            return Response::text($result->text);
        }

        return Response::make(Response::text($result->text))
            ->withStructuredContent($result->structured);
    }
}
