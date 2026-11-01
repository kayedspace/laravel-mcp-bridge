<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository as Context;
use Illuminate\Pipeline\Pipeline;
use KayedSpace\McpBridge\Audit\AuditRecorder;
use KayedSpace\McpBridge\Contracts\AuthorizesTools;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Enums\Outcome;
use KayedSpace\McpBridge\Events\ToolCalled;
use KayedSpace\McpBridge\Output\ErrorMapper;
use KayedSpace\McpBridge\Output\OutputShaper;
use KayedSpace\McpBridge\Sources\SourceRegistry;
use Throwable;

/**
 * What happens between an agent asking and an agent being answered:
 * authorize, run, shape, record.
 *
 * One object because there is more than one way in. A connected client
 * arrives through laravel/mcp, a developer arrives through
 * `mcp-bridge:call`, and an application's own test suite arrives through the
 * testing helpers. If each spelled this sequence out, the two that are not
 * the real path would drift from it, and the test that proved a tool was
 * refused would be proving something else.
 */
class ToolRunner
{
    /** The keys every log line written during a call carries. */
    public const CONTEXT = ['mcp.tool', 'mcp.request_id', 'mcp.actor'];

    public function __construct(
        protected SourceRegistry $sources,
        protected AuthorizesTools $gate,
        protected OutputShaper $shaper,
        protected ErrorMapper $errors,
        protected AuditRecorder $audit,
        protected Dispatcher $events,
        protected IdempotencyKeys $idempotency,
        protected Container $container,
        protected Context $context,
        protected Repository $config,
        protected ExceptionHandler $exceptions,
    ) {}

    public function run(ToolCall $call): ToolResult
    {
        $definition = $call->definition;
        $source = $this->sources->get($definition->source);

        if ($source === null) {
            return ToolResult::error(
                'MCP_BRIDGE_SERVER_ERROR',
                "The [{$definition->source}] source is not registered. Rebuild the manifest.",
            );
        }

        $started = hrtime(true);

        // Before the gate, so a destructive call with a typo is refused
        // outright instead of being previewed, confirmed by the user and then
        // refused after the handle was spent. Only for a caller the gate
        // would list the tool for, so nobody else learns a field name.
        $unknown = $this->gate->visible($definition, $call->actor) ? $this->unknownArguments($call) : null;

        if ($unknown !== null) {
            $this->record($call, $unknown, $this->elapsed($started), Outcome::Refused);

            return $unknown;
        }

        $refused = $this->gate->check($call);

        if ($refused !== null) {
            // Recorded too: "this agent tried and was not allowed" is the
            // entry that matters most, and a confirmation handshake is the
            // start of a destructive call rather than a non-event.
            // No status: the gate answered, so nothing was executed to have one.
            $this->record($call, $refused, $this->elapsed($started), Outcome::Refused);

            return $refused;
        }

        // After the gate, never before it. A replay is an answer this caller
        // is still allowed to have, so a token that has lost the scope is
        // refused above rather than handed what the token that held it was
        // given. A refusal therefore never claims the key either, which is
        // what makes a destructive tool callable with one: the first call is
        // answered with a confirmation handle, and the second gets to run.
        //
        // The cost is that a retry spends a rate-limit slot. That is the
        // cheaper of the two mistakes by a wide margin.
        $replayed = $this->idempotency->claim($call);

        if ($replayed !== null) {
            $this->record($call, $replayed, $this->elapsed($started), Outcome::Allowed);

            return $replayed;
        }

        $status = null;
        $shaped = $this->execute($source, $call, $status);

        // Refused when a middleware answered before the source ran, failed
        // when the source answered badly, allowed otherwise.
        $outcome = match (true) {
            ! $shaped->isError => Outcome::Allowed,
            $status === null => Outcome::Refused,
            default => Outcome::Failed,
        };

        $this->record($call, $shaped, $this->elapsed($started), $outcome, $status);

        $this->idempotency->record($call, $shaped);

        return $shaped;
    }

    /**
     * An argument no field declares is refused rather than dropped, because
     * an agent that "filtered" and was handed an unfiltered page acts on the
     * wrong data without knowing it. The schema cannot say this itself: the
     * JSON Schema subset the compiler emits has no additionalProperties.
     *
     * A tool that declares nothing is left alone: a remote tool with a
     * free-form schema reads the same as one with no inputs, and only the
     * first would be harmed.
     */
    protected function unknownArguments(ToolCall $call): ?ToolResult
    {
        $fields = $call->definition->input->fields;

        if ($fields === []) {
            return null;
        }

        $declared = [];
        $visible = [];

        foreach ($fields as $field) {
            $name = explode('.', $field->path, 2)[0];
            $declared[$name] = true;

            if (! $field->hidden) {
                $visible[$name] = true;
            }
        }

        $unknown = array_keys(array_diff_key($call->arguments, $declared));

        if ($unknown === []) {
            return null;
        }

        return ToolResult::error('MCP_BRIDGE_UNKNOWN_ARGUMENT', sprintf(
            'Unknown argument [%s]. [%s] accepts: %s.',
            implode(', ', array_map('strval', $unknown)),
            $call->definition->name,
            $visible === [] ? 'no arguments' : implode(', ', array_keys($visible)),
        ));
    }

    /**
     * The call through the application's own middleware and then the
     * source, with the log context set for the whole of it.
     *
     * The middleware is the one seam between "may this run" and "run it":
     * where a tenant is initialised for a source that runs no HTTP
     * middleware, a tracing span is opened, or a call is metered. A
     * middleware answers with a result of its own to stop the call, the way
     * route middleware returns a response.
     *
     * The context is what makes a log line a route, a command or a job
     * writes during the call name the tool and the request id that caused
     * it, and because context rides on a queued job, a line the job writes
     * later does too.
     */
    protected function execute(Source $source, ToolCall $call, ?int &$status): ToolResult
    {
        // A middleware that does not exist is a tenant never initialised or
        // a meter never run, silently. Every call stops instead, naming it.
        $missing = $this->missingMiddleware();

        if ($missing !== null) {
            $status = 500;

            return ToolResult::error(
                'MCP_BRIDGE_SERVER_ERROR',
                "The call middleware [{$missing}] does not exist. Fix execution.middleware.",
            );
        }

        $this->context->add([
            'mcp.tool' => $call->definition->name,
            'mcp.request_id' => $call->requestId,
            'mcp.actor' => $this->actorId($call),
        ]);

        try {
            $result = (new Pipeline($this->container))
                ->send($call)
                ->through($this->middleware())
                ->then(function (ToolCall $call) use ($source, &$status): ToolResult {
                    $result = $source->execute($call);
                    $status = $result->status;

                    return $result->successful()
                        ? $this->shaper->shape($result, $call->definition)
                        : $this->errors->map($result, $call->definition);
                });
        } catch (Throwable $exception) {
            // The executors catch their own; this is a middleware, the shaper
            // or the mapper. Reported like any application error, answered
            // with the detail the configuration allows, recorded as a
            // failure, and the idempotency key released by the caller so a
            // retry runs rather than being told the call is still in flight.
            $this->exceptions->report($exception);
            $status = 500;

            return $this->errors->map(SourceResult::failed(500, null, $exception), $call->definition);
        } finally {
            $this->context->forget(self::CONTEXT);
        }

        return $result instanceof ToolResult
            ? $result
            : ToolResult::error('MCP_BRIDGE_SERVER_ERROR', 'A middleware answered with something that is not a tool result.');
    }

    /** @return list<class-string> */
    protected function middleware(): array
    {
        $middleware = [];

        foreach ($this->config->array('mcp-bridge.execution.middleware', []) as $class) {
            if (is_string($class) && class_exists($class)) {
                $middleware[] = $class;
            }
        }

        return $middleware;
    }

    /** The first configured middleware that is not a class, or null. */
    protected function missingMiddleware(): ?string
    {
        foreach ($this->config->array('mcp-bridge.execution.middleware', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                return is_scalar($class) ? (string) $class : gettype($class);
            }
        }

        return null;
    }

    protected function actorId(ToolCall $call): ?string
    {
        $identifier = $call->actor->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : null;
    }

    /**
     * The trail and the event are one step, so an outcome cannot reach the
     * audit without reaching a listener, or the other way round.
     */
    protected function record(ToolCall $call, ToolResult $result, int $durationMs, Outcome $outcome, ?int $status = null): void
    {
        $this->audit->record($call, $result, $durationMs, $outcome, $status);

        $this->events->dispatch(new ToolCalled($call, $result, $outcome, $durationMs));
    }

    protected function elapsed(float|int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
