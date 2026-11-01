<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\McpBridgeManager;
use KayedSpace\McpBridge\Query\ApiQuery;
use KayedSpace\McpBridge\Schema\ModelSchema;
use KayedSpace\McpBridge\Sources\ModelSource;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Runs a model operation against Eloquent, with the policy asked first every
 * time.
 *
 * The policy is asked about the record, not about the class, wherever a record
 * exists: "may this user update course 7" is the question the application's
 * own code would ask, and a model tool that asked anything weaker would be a
 * way around it.
 */
class ModelExecutor implements ExecutesTools
{
    public function __construct(
        protected Application $app,
        protected Gate $gate,
        protected ApiQuery $queries,
        protected ModelSchema $schema,
        protected WireSerializer $wire,
        protected McpBridgeManager $bridge,
        protected Repository $config,
    ) {}

    public function execute(ToolCall $call): SourceResult
    {
        $target = $call->definition->target;

        if (! $target instanceof ClassTarget || ! is_subclass_of($target->class, Model::class)) {
            return SourceResult::failed(500, ['message' => 'This tool is not backed by a model.']);
        }

        $snapshot = StateSnapshot::capture($this->app);

        try {
            return $this->bridge->actingAs(
                $call->actor,
                fn (): SourceResult => $this->run($target->class, (string) $target->method, $call),
            );
        } catch (AuthorizationException $exception) {
            return SourceResult::failed(403, [
                'message' => $exception->getMessage() === ''
                    ? 'Not allowed for this user. Do not retry.'
                    : $exception->getMessage(),
            ], $exception);
        } catch (ModelNotFoundException) {
            return SourceResult::failed(404, ['message' => 'No such record.'], null, [
                'code' => 'MCP_BRIDGE_NOT_FOUND',
            ]);
        } catch (HttpException $exception) {
            // A filter, sort or include the declaration does not allow: the
            // query layer answers 400 with the allowed names, as for a route.
            return SourceResult::failed($exception->getStatusCode(), ['message' => $exception->getMessage()], $exception);
        } catch (Throwable $exception) {
            return SourceResult::failed(500, null, $exception);
        } finally {
            $snapshot->restore();
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function run(string $model, string $operation, ToolCall $call): SourceResult
    {
        return match ($operation) {
            'list' => $this->list($model, $call),
            'get' => $this->get($model, $call),
            'create' => $this->create($model, $call),
            'update' => $this->update($model, $call),
            'delete' => $this->delete($model, $call),
            default => SourceResult::failed(500, [
                'message' => "[{$operation}] is not a model operation.",
            ]),
        };
    }

    /** @param class-string<Model> $model */
    protected function list(string $model, ToolCall $call): SourceResult
    {
        $this->authorize('list', $model);

        // The arguments become a query string and go through the same applier
        // a route list endpoint uses, so filters, sorts and paging behave
        // identically wherever the list came from.
        $request = Request::create('/?'.http_build_query($this->wire->query($call)));

        $definition = $this->schema->definitionFor($model, $call->definition->output?->maxItems);
        $queries = $this->queries->withRequest($request);
        $query = $queries->eloquentQuery($definition, $model);

        // The page is passed in, because the paginator would otherwise read
        // it from the container's request, which is the MCP call and never
        // carries one.
        $page = $query->paginate(page: max($request->integer('page', 1), 1));
        $relations = $queries->requestedRelations($definition);

        // As arrays, the way `get` answers: the shaper and the redactor walk
        // arrays, and a Model object handed to them passed through whole,
        // redacted keys included.
        return SourceResult::ok([
            'data' => array_map(fn (Model $record): array => $this->serialise($record, $relations), $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** @param class-string<Model> $model */
    protected function get(string $model, ToolCall $call): SourceResult
    {
        $record = $this->find($model, $call);

        $this->authorize('get', $record);

        // Related data only after the policy answered about the record, and
        // only what the same declaration offered the list.
        $request = Request::create('/?'.http_build_query($this->wire->query($call)));
        $definition = $this->schema->definitionFor($model);
        $queries = $this->queries->withRequest($request);

        $queries->loadIncludes($record, $definition);

        return SourceResult::ok(['data' => $this->serialise($record, $queries->requestedRelations($definition))]);
    }

    /**
     * The record with only the relations the call asked for. A model's own
     * `$with` loads others on every query, and no ability was asked about
     * those; `toArray()` then keeps `$hidden` and `$visible` for the record
     * and for everything still on it.
     *
     * @param  list<string>  $relations
     * @return array<array-key, mixed>
     */
    protected function serialise(Model $record, array $relations): array
    {
        $record->setRelations(Arr::only($record->getRelations(), $relations));

        return $record->toArray();
    }

    /** @param class-string<Model> $model */
    protected function create(string $model, ToolCall $call): SourceResult
    {
        $this->authorize('create', $model);

        $record = new $model;
        $record->fill($this->attributes($call));
        $record->save();

        return SourceResult::ok(['data' => $record->attributesToArray()], ['created' => true]);
    }

    /** @param class-string<Model> $model */
    protected function update(string $model, ToolCall $call): SourceResult
    {
        $record = $this->find($model, $call);

        $this->authorize('update', $record);

        // Not filled from the actor here: the field is the record's owner,
        // and the policy that allowed the update was asked "may update",
        // never "may reassign".
        $record->fill($this->attributes($call, except: [$record->getKeyName()], fill: false));
        $record->save();

        return SourceResult::ok(['data' => $record->attributesToArray()]);
    }

    /** @param class-string<Model> $model */
    protected function delete(string $model, ToolCall $call): SourceResult
    {
        $record = $this->find($model, $call);

        $this->authorize('delete', $record);

        $record->delete();

        return SourceResult::ok(['deleted' => true, 'id' => $record->getKey()]);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function find(string $model, ToolCall $call): Model
    {
        $instance = new $model;
        $key = $call->argument($instance->getKeyName());

        if (! is_scalar($key)) {
            throw new ModelNotFoundException;
        }

        return $model::query()->findOrFail($key);
    }

    /**
     * Only the fields the schema advertised, which are the model's own
     * mass-assignable attributes. Eloquent would enforce that again on fill(),
     * and this makes the refusal visible as an input that was never offered
     * rather than a value silently dropped.
     *
     * @param  list<string>  $except
     * @param  bool  $fill  whether fields filled from the actor are set, which only a create wants
     * @return array<string, mixed>
     */
    protected function attributes(ToolCall $call, array $except = [], bool $fill = true): array
    {
        $attributes = [];

        foreach ($call->definition->input->fields as $field) {
            // A model write has no controller to fill this, so the executor
            // does. Without it the field is hidden from the agent and set by
            // nobody, which a non-nullable column turns into a failure every
            // time rather than a security measure.
            if ($field->filledFrom === 'auth' && ! in_array($field->path, $except, true)) {
                $value = $fill ? $call->filledFromActor() : null;

                if ($value !== null) {
                    $attributes[$field->path] = $value;
                }

                continue;
            }

            if ($field->wire?->in !== WireIn::Body || $field->hidden) {
                continue;
            }

            if (in_array($field->path, $except, true) || str_contains($field->path, '*')) {
                continue;
            }

            $value = $call->argument($field->path);

            if ($value !== null) {
                $attributes[$field->path] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param  class-string<Model>|Model  $subject
     */
    protected function authorize(string $operation, string|Model $subject): void
    {
        $ability = ModelSource::ABILITIES[$operation] ?? $operation;

        if (! $this->config->boolean('mcp-bridge.sources.model.require_policy', true)) {
            return;
        }

        $this->gate->authorize($ability, $subject);
    }
}
