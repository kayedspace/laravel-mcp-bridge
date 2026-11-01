<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use BackedEnum;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\McpBridgeManager;
use KayedSpace\McpBridge\Schema\ParameterMapper;
use KayedSpace\McpBridge\Schema\ParameterSchema;
use RuntimeException;
use Throwable;

/**
 * Builds a job from the agent's arguments and hands it to the dispatcher,
 * which is the same entry point application code uses.
 *
 * Queued by default, onto a queue of its own: agent-dispatched work must not
 * be able to starve or poison the application's queues, and keeping it
 * separate is also what makes it visible in Horizon as something an agent
 * asked for. Synchronous dispatch is opt-in through #[McpQueue(sync: true)],
 * for work short enough that an agent waiting is better than an agent being
 * told "accepted" and never learning the outcome.
 */
class JobExecutor implements ExecutesTools
{
    public function __construct(
        protected Application $app,
        protected Dispatcher $dispatcher,
        protected ParameterMapper $parameters,
        protected McpBridgeManager $bridge,
        protected JobStatuses $statuses,
        protected Repository $config,
    ) {}

    public function execute(ToolCall $call): SourceResult
    {
        $target = $call->definition->target;

        if (! $target instanceof ClassTarget) {
            return SourceResult::failed(500, ['message' => 'This tool is not backed by a job.']);
        }

        $snapshot = StateSnapshot::capture($this->app);

        try {
            $job = $this->build($target->class, $call);

            return $this->bridge->actingAs(
                $call->actor,
                fn (): SourceResult => $this->dispatch($job, $target, $call),
            );
        } catch (ModelNotFoundException) {
            return SourceResult::failed(404, [
                'message' => 'One of the records this job needs does not exist.',
            ], null, ['code' => 'MCP_BRIDGE_NOT_FOUND']);
        } catch (Throwable $exception) {
            return SourceResult::failed(500, [
                'message' => 'The job could not be dispatched.',
            ], $exception, ['code' => 'MCP_BRIDGE_JOB_FAILED']);
        } finally {
            $snapshot->restore();
        }
    }

    /**
     * Agent arguments as constructor arguments, in signature order. A model
     * parameter is resolved from its key here, so the job receives the record
     * it declared rather than an id it would have to look up.
     *
     * @param  class-string  $class
     */
    protected function build(string $class, ToolCall $call): object
    {
        $schema = $this->parameters->forClass($class);
        $arguments = [];

        foreach ($schema->input->fields as $field) {
            if ($field->wire?->in !== WireIn::Constructor || str_contains($field->path, '*')) {
                continue;
            }

            // Hidden and filled-from-actor live on the compiled definition,
            // which is where the compiler put them; the parameter schema here
            // only knows the types. Read the decision from the definition and
            // the type from the parameter, or a hidden field is the agent's
            // to set after all.
            $compiled = $call->definition->input->get($field->path);

            $value = $compiled?->filledFrom === 'auth'
                ? $call->filledFromActor()
                : (($compiled->hidden ?? false) ? null : $call->argument($field->path));

            if ($value === null) {
                continue;
            }

            $arguments[$field->path] = $this->argument($schema, $field->path, $value);
        }

        // A spatie/laravel-data parameter is built from the flat arguments
        // and validated by its own rules; the flat keys stay, since the
        // container only reads overrides by parameter name.
        foreach ($schema->data as $name => $data) {
            $arguments[$name] = $data::validateAndCreate($call->visibleArguments());
        }

        $job = $this->app->make($class, $arguments);

        // The container is used rather than `new`, so a job that also takes a
        // service in its constructor still resolves.
        if (! is_object($job)) {
            throw new RuntimeException("[{$class}] did not resolve to an object.");
        }

        return $job;
    }

    /**
     * One argument, as the type the constructor declared: a model from its
     * key, an enum case from its value, a date from its string.
     */
    protected function argument(ParameterSchema $schema, string $name, mixed $value): mixed
    {
        $model = $schema->models[$name] ?? null;

        if ($model !== null) {
            return $model::query()->findOrFail(is_scalar($value) ? $value : 0);
        }

        $class = $schema->coerce[$name] ?? null;

        if ($class === null || ! is_scalar($value)) {
            return $value;
        }

        if (is_subclass_of($class, BackedEnum::class)) {
            // A backing value is an int or a string; anything else the agent
            // sent is not a case of this enum, and from() says so.
            return $class::from(is_int($value) ? $value : (string) $value);
        }

        return Date::parse((string) $value);
    }

    protected function dispatch(object $job, ClassTarget $target, ToolCall $call): SourceResult
    {
        if ($this->isSync($call)) {
            $this->dispatcher->dispatchSync($job);

            return SourceResult::ok(
                ['status' => 'completed', 'job' => class_basename($target->class)],
                ['sync' => true],
            );
        }

        $this->onQueue($job, $call);

        // Tracking first, so it sits outside the re-authorization check and
        // a job refused in the worker is recorded as failed, with the reason.
        $id = $this->tracks($job, $target->class, $call);

        if (! $this->reauthorizes($job, $call)) {
            return SourceResult::failed(500, [
                'message' => 'This job cannot carry the re-authorization check, so it was not dispatched.',
            ], null, ['code' => 'MCP_BRIDGE_SERVER_ERROR']);
        }

        $this->dispatcher->dispatch($job);

        // The id is this package's, not the queue driver's, which may hand
        // back nothing. Null only for a job that cannot carry middleware,
        // so the agent is never told it can track something it cannot.
        return SourceResult::ok([
            'status' => 'queued',
            'job' => class_basename($target->class),
            'queue' => $this->queue($call),
            'id' => $id,
        ], ['queued' => true]);
    }

    /**
     * Give the job a record and the middleware that moves it along. Null when
     * the job cannot carry middleware, in which case there is nothing to ask.
     *
     * @param  class-string  $class
     */
    protected function tracks(object $job, string $class, ToolCall $call): ?string
    {
        if (! property_exists($job, 'middleware')) {
            return null;
        }

        $id = $this->statuses->queued($call, $class);

        /** @var list<object> $existing */
        $existing = is_array($job->middleware) ? $job->middleware : [];

        $job->middleware = [...$existing, new TracksJobStatus($id)];

        return $id;
    }

    /**
     * Attach the run-time authority check, and say whether it is attached.
     *
     * Laravel merges a job's `$middleware` property with its `middleware()`
     * method, so appending to the property leaves whatever the job already
     * declares alone.
     *
     * A job that declares `$middleware` itself as anything but public cannot
     * be given one from out here. That returns false rather than dispatching
     * regardless: a revoked token that still runs queued work is the failure
     * this exists to prevent, so the honest answer is to refuse and let the
     * developer add the middleware to the job's own `middleware()`.
     *
     * Only queued dispatch needs it. A sync job has already run by the time
     * the gate's answer could change.
     */
    protected function reauthorizes(object $job, ToolCall $call): bool
    {
        $abilities = $call->definition->authority->abilities;

        if ($abilities === [] || ! $this->config->boolean('mcp-bridge.sources.job.reauthorize', true)) {
            return true;
        }

        $identifier = $call->actor->getAuthIdentifier();

        if (! is_scalar($identifier)) {
            return false;
        }

        $middleware = new ReauthorizesJob(
            tool: $call->definition->name,
            actorType: $call->actor::class,
            actorId: is_int($identifier) ? $identifier : (string) $identifier,
            abilities: $abilities,
        );

        // Illuminate\Bus\Queueable declares `$middleware`, so a standard job
        // already has the property and nothing dynamic is created here. Its
        // `through()` is not used because that replaces the list rather than
        // adding to it, and a job's own middleware is not ours to drop.
        if (! property_exists($job, 'middleware')) {
            return false;
        }

        /** @var list<object> $existing */
        $existing = is_array($job->middleware) ? $job->middleware : [];

        $job->middleware = [...$existing, $middleware];

        return true;
    }

    protected function isSync(ToolCall $call): bool
    {
        return ($call->definition->queue->sync ?? false)
            || ! $call->definition->target instanceof ClassTarget
            || ! is_subclass_of($call->definition->target->class, ShouldQueue::class);
    }

    /**
     * onQueue(), onConnection() and delay() come from the Queueable trait, so
     * no type declares them and each is asked for before it is used. A job
     * without them has nowhere to put a queue name, and the bus runs it
     * synchronously anyway.
     */
    protected function onQueue(object $job, ToolCall $call): void
    {
        if (method_exists($job, 'onQueue')) {
            $job->onQueue($this->queue($call));
        }

        $connection = $call->definition->queue->connection ?? null;

        if ($connection !== null && method_exists($job, 'onConnection')) {
            $job->onConnection($connection);
        }

        $delay = $call->definition->queue->delay ?? null;

        if ($delay !== null && method_exists($job, 'delay')) {
            $job->delay($delay);
        }
    }

    protected function queue(ToolCall $call): string
    {
        return $call->definition->queue->queue
            ?? $this->config->string('mcp-bridge.sources.job.queue', 'mcp-bridge');
    }
}
