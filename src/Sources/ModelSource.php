<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Discovery\AttributeReader;
use KayedSpace\McpBridge\Discovery\ClassScanner;
use KayedSpace\McpBridge\Discovery\ToolNamer;
use KayedSpace\McpBridge\Execution\ModelExecutor;
use KayedSpace\McpBridge\Schema\ModelSchema;
use KayedSpace\McpBridge\Security\PolicyRequirement;
use KayedSpace\McpBridge\Sources\Concerns\RefusesCandidates;

/**
 * Eloquent models as tools: one per operation, per model.
 *
 * This is the source with the least to inherit and the most reach, so it is
 * the most constrained. A model tool exists only when the model opts in **and**
 * a policy exists **and** that policy has a method for the operation: the
 * policy decides which operations exist at all, rather than being asked
 * afterwards whether one was allowed. A model with no policy produces no
 * tools, however much it opted in.
 *
 * Only mass-assignable attributes are exposed, because a model that guards an
 * attribute from mass assignment is saying it is not for arbitrary callers,
 * and an agent is the most arbitrary caller there is.
 */
class ModelSource implements Source
{
    use RefusesCandidates;

    /** Each operation and the policy method that decides it. */
    public const ABILITIES = [
        'list' => 'viewAny',
        'get' => 'view',
        'create' => 'create',
        'update' => 'update',
        'delete' => 'delete',
    ];

    public function __construct(
        protected ClassScanner $scanner,
        protected ToolNamer $namer,
        protected AttributeReader $attributes,
        protected ModelSchema $schema,
        protected ModelExecutor $executor,
        protected PolicyRequirement $policies,
        protected Repository $config,
    ) {}

    public function key(): string
    {
        return 'model';
    }

    public function discover(): iterable
    {
        $candidates = [];

        foreach ($this->models() as $model) {
            foreach ($this->operationsFor($model) as $operation) {
                $candidate = $this->candidate($model, $operation);

                $candidates[$candidate->id] = $candidate;
            }
        }

        ksort($candidates);

        return array_values($candidates);
    }

    public function schema(ToolCandidate $candidate): InputSchema
    {
        $target = $candidate->target;
        $operation = $candidate->meta['operation'] ?? null;

        if (! $target instanceof ClassTarget || ! is_string($operation)) {
            return InputSchema::empty();
        }

        $model = $target->class;

        if (! is_subclass_of($model, Model::class)) {
            return InputSchema::empty();
        }

        $rules = $candidate->meta['rules'] ?? null;

        return $this->schema->forOperation(
            $model,
            $operation,
            is_string($rules) && class_exists($rules) ? $rules : null,
            $candidate->declared?->outputMaxItems,
        );
    }

    public function validate(ToolCandidate $candidate): array
    {
        $refusals = $this->refusals($candidate);

        if ($refusals !== []) {
            return $refusals;
        }

        $target = $candidate->target;
        $operation = $candidate->meta['operation'] ?? null;

        if (! $target instanceof ClassTarget || ! is_string($operation)) {
            return [Refusal::notExposed($candidate->id, 'any')];
        }

        // Named by the model's #[McpTool(operations:)] but not enabled in
        // configuration: refused rather than dropped, so `--refused` says
        // where the switch is.
        if (! in_array($operation, $this->operations(), true)) {
            return [Refusal::operationNotEnabled($candidate->id, $operation)];
        }

        return $this->policyRefusals($target->class, $operation, $candidate->id);
    }

    /**
     * The configured operations, narrowed by what the model itself asks for.
     * The attribute's list is walked in full so an operation the
     * configuration excludes still becomes a candidate and is refused with
     * the reason, rather than vanishing.
     *
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    protected function operationsFor(string $model): array
    {
        $declared = array_values(array_filter(
            $this->attributes->read($model)->tool->operations ?? [],
            fn (string $operation): bool => isset(self::ABILITIES[$operation]),
        ));

        return $declared === [] ? $this->operations() : $declared;
    }

    public function execute(ToolCall $call): SourceResult
    {
        return $this->executor->execute($call);
    }

    /**
     * @param  class-string  $model
     * @return list<Refusal>
     */
    protected function policyRefusals(string $model, string $operation, string $id): array
    {
        if (! $this->config->boolean('mcp-bridge.sources.model.require_policy', true)) {
            return [];
        }

        $refusal = $this->policies->silentOn($model, self::ABILITIES[$operation] ?? $operation, $id);

        return $refusal === null ? [] : [$refusal];
    }

    /** @return list<class-string<Model>> */
    protected function models(): array
    {
        $models = [];

        foreach ($this->scanner->in($this->paths(['app/Models']), Model::class) as $class) {
            if (is_subclass_of($class, Model::class)) {
                $models[] = $class;
            }
        }

        return $models;
    }

    protected function settings(): Repository
    {
        return $this->config;
    }

    /**
     * The operations the application configured. An empty list is none: a
     * list that quietly meant "the defaults" was a setting that exposed the
     * most when it said the least.
     *
     * @return list<string>
     */
    protected function operations(): array
    {
        $operations = array_values(array_filter(
            $this->config->array('mcp-bridge.sources.model.operations', ['list', 'get']),
            'is_string',
        ));

        return array_values(array_filter(
            $operations,
            fn (string $operation): bool => isset(self::ABILITIES[$operation]),
        ));
    }

    /** @param class-string<Model> $model */
    protected function candidate(string $model, string $operation): ToolCandidate
    {
        $declarations = $this->attributes->read($model);

        return new ToolCandidate(
            source: $this->key(),
            id: "model:{$model}:{$operation}",
            target: new ClassTarget($model, $operation),
            suggestedName: $this->namer->forModel($model, $operation),
            title: $declarations->tool?->title,
            description: $this->describe($model, $operation),
            groups: $declarations->groups,
            safety: $this->safety($operation)->with($declarations->safety),
            declarations: $declarations->raw,
            meta: [
                'opted_in' => $declarations->optedIn(),
                'ignored' => $declarations->ignored,
                'class' => $model,
                'operation' => $operation,
                'rules' => $declarations->input?->request,
            ],
            declared: $declarations->toDeclared(),
        );
    }

    /** @param class-string<Model> $model */
    protected function describe(string $model, string $operation): string
    {
        $one = Str::lower(Str::headline(class_basename($model)));
        $many = Str::plural($one);

        return match ($operation) {
            'list' => "List {$many}, with filters, sorting and pagination.",
            'get' => "Read one {$one} by its id.",
            'create' => "Create a {$one}.",
            'update' => "Change a {$one} that already exists.",
            'delete' => "Delete a {$one}.",
            default => "Operate on a {$one}.",
        };
    }

    /**
     * The operation says exactly what the work is, which is the one case in
     * this package where safety can be inferred with confidence.
     */
    protected function safety(string $operation): Safety
    {
        return match ($operation) {
            'list', 'get' => new Safety(readOnly: true, destructive: false, idempotent: true, openWorld: false),
            'update' => new Safety(readOnly: false, destructive: false, idempotent: true, openWorld: false),
            'delete' => new Safety(readOnly: false, destructive: true, idempotent: true, openWorld: false),
            default => new Safety(readOnly: false, destructive: false, idempotent: false, openWorld: false),
        };
    }
}
