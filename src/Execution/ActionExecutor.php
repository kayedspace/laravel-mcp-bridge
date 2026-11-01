<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\McpBridgeManager;
use KayedSpace\McpBridge\Schema\FormRequestReader;
use KayedSpace\McpBridge\Schema\ParameterMapper;
use KayedSpace\McpBridge\Support\Keys;
use Throwable;

/**
 * Runs an action class the way the application's own code does: validate with
 * its `rules()`, ask its `authorize()`, then call it through the container.
 *
 * Those two methods are called rather than reimplemented, which is what makes
 * an action behave for an agent exactly as it does over HTTP, and what lets an
 * action package this one has never heard of work anyway.
 */
class ActionExecutor implements ExecutesTools
{
    public function __construct(
        protected Application $app,
        protected Container $container,
        protected ValidationFactory $validator,
        protected FormRequestReader $requests,
        protected ParameterMapper $parameters,
        protected McpBridgeManager $bridge,
    ) {}

    public function execute(ToolCall $call): SourceResult
    {
        $target = $call->definition->target;

        if (! $target instanceof ClassTarget || $target->method === null) {
            return SourceResult::failed(500, ['message' => 'This tool is not backed by an action.']);
        }

        $snapshot = StateSnapshot::capture($this->app);

        try {
            return $this->bridge->actingAs($call->actor, fn (): SourceResult => $this->run($target, $call));
        } catch (ValidationException $exception) {
            return SourceResult::failed(422, [
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception);
        } catch (AuthorizationException $exception) {
            return SourceResult::failed(403, ['message' => $exception->getMessage()], $exception);
        } catch (ModelNotFoundException) {
            return SourceResult::failed(404, [
                'message' => 'One of the records this action needs does not exist.',
            ], null, ['code' => 'MCP_BRIDGE_NOT_FOUND']);
        } catch (Throwable $exception) {
            return SourceResult::failed(500, null, $exception);
        } finally {
            $snapshot->restore();
        }
    }

    protected function run(ClassTarget $target, ToolCall $call): SourceResult
    {
        $action = $this->container->make($target->class);

        if (! is_object($action)) {
            return SourceResult::failed(500, ['message' => 'The action did not resolve to an object.']);
        }

        $arguments = $this->dataObjects($target, $this->validated($target->class, $call));

        $this->authorize($action, $arguments);

        /** @var callable $callable */
        $callable = [$action, (string) $target->method];

        return SourceResult::ok($this->container->call($callable, $arguments));
    }

    /**
     * A parameter typed as a spatie/laravel-data object is built from the
     * flat arguments the agent sent, validated by the object's own rules,
     * which is the same validation `validateAndCreate()` gives a controller.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function dataObjects(ClassTarget $target, array $arguments): array
    {
        foreach ($this->parameters->forMethod($target->class, (string) $target->method)->data as $name => $class) {
            $arguments[$name] = $class::validateAndCreate($arguments);
        }

        return $arguments;
    }

    /**
     * The agent's arguments, validated by the action's own rules when it has
     * them. Validation failures reach the agent as the field-by-field errors
     * the ErrorMapper turns into something it can correct.
     *
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    protected function validated(string $class, ToolCall $call): array
    {
        // Hidden fields are dropped before anything else looks at them, so
        // an action without rules is no more reachable than one with them.
        $arguments = $call->visibleArguments();

        $rules = method_exists($class, 'rules') ? $this->requests->rulesFor($class) : [];

        if ($rules === []) {
            return $arguments;
        }

        // Keys the rules do not mention are dropped rather than forwarded: an
        // action's rules are its statement of what it accepts.
        return Keys::strings($this->validator->make($arguments, $rules)->validate());
    }

    /**
     * An action's own authorize(), called through the container so it can take
     * what it needs. A false answer is a refusal, which is what the method
     * means wherever else it is used.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function authorize(object $action, array $arguments): void
    {
        if (! method_exists($action, 'authorize')) {
            return;
        }

        /** @var callable $callable */
        $callable = [$action, 'authorize'];

        if ($this->container->call($callable, $arguments) === false) {
            throw new AuthorizationException('This action is not allowed for the calling user.');
        }
    }
}
