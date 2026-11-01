<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Discovery\AttributeReader;
use KayedSpace\McpBridge\Discovery\ClassScanner;
use KayedSpace\McpBridge\Discovery\ToolNamer;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Execution\ActionExecutor;
use KayedSpace\McpBridge\Schema\FormRequestReader;
use KayedSpace\McpBridge\Schema\ParameterMapper;
use KayedSpace\McpBridge\Schema\RuleMapper;
use KayedSpace\McpBridge\Sources\Concerns\RefusesCandidates;

/**
 * Action classes as tools: one use case per class, which is the shape a lot of
 * Laravel applications already organise their write paths in.
 *
 * An action that declares `rules()` is read from those rules, which is what
 * makes `lorisleiva/laravel-actions` work without this package depending on
 * it: the rules are the declaration, wherever they came from. An action
 * without them is read from its own signature.
 *
 * The same holds for `authorize()`. It is called at run time rather than
 * reimplemented, so an action behaves for an agent exactly as it does over
 * HTTP, and a package this one has never heard of gets the same treatment.
 */
class ActionSource implements Source
{
    use RefusesCandidates;

    /** The entry points an action class may offer, most specific first. */
    protected const METHODS = ['handle', '__invoke'];

    public function __construct(
        protected ClassScanner $scanner,
        protected ToolNamer $namer,
        protected AttributeReader $attributes,
        protected ParameterMapper $parameters,
        protected FormRequestReader $requests,
        protected RuleMapper $rules,
        protected ActionExecutor $executor,
        protected Repository $config,
    ) {}

    public function key(): string
    {
        return 'action';
    }

    public function discover(): iterable
    {
        $candidates = [];

        foreach ($this->scanner->in($this->paths(['app/Actions'])) as $class) {
            $method = $this->entryPoint($class);

            if ($method === null) {
                continue;
            }

            $candidate = $this->candidate($class, $method);

            $candidates[$candidate->id] = $candidate;
        }

        ksort($candidates);

        return array_values($candidates);
    }

    public function schema(ToolCandidate $candidate): InputSchema
    {
        $target = $candidate->target;

        if (! $target instanceof ClassTarget || $target->method === null) {
            return InputSchema::empty();
        }

        $rules = $this->rulesOf($target->class);

        // Rules describe the input an action validates, which is a better
        // description of what it accepts than its signature: the signature
        // says what it needs, the rules say what is allowed.
        if ($rules !== []) {
            $schema = InputSchema::empty();

            foreach ($rules as $path => $rule) {
                $schema = $schema->with($this->rules->map((string) $path, $rule, WireIn::Constructor));
            }

            return $schema->sorted();
        }

        return $this->parameters->forMethod($target->class, $target->method)->input;
    }

    public function validate(ToolCandidate $candidate): array
    {
        return $this->refusals($candidate);
    }

    public function execute(ToolCall $call): SourceResult
    {
        return $this->executor->execute($call);
    }

    protected function settings(): Repository
    {
        return $this->config;
    }

    /** @param class-string $class */
    protected function entryPoint(string $class): ?string
    {
        foreach (self::METHODS as $method) {
            if (method_exists($class, $method)) {
                return $method;
            }
        }

        return null;
    }

    /** @param class-string $class */
    protected function candidate(string $class, string $method): ToolCandidate
    {
        $declarations = $this->attributes->read($class, $method);

        // An action read from its rules is buildable by definition: the rules
        // describe JSON. Only a signature can be unreadable.
        $unsupported = $this->rulesOf($class) === []
            ? $this->parameters->forMethod($class, $method)->unsupported
            : [];

        return new ToolCandidate(
            source: $this->key(),
            id: "action:{$class}",
            target: new ClassTarget($class, $method),
            suggestedName: $this->namer->forClass($class, $declarations->tool?->name),
            title: $declarations->tool?->title,
            description: $declarations->tool->description ?? $this->describe($class),
            groups: $declarations->groups,
            safety: $this->safety()->with($declarations->safety),
            declarations: $declarations->raw,
            meta: [
                'opted_in' => $declarations->optedIn(),
                'ignored' => $declarations->ignored,
                'class' => $class,
                'action' => $method,
                'unsupported' => $unsupported,
            ],
            declared: $declarations->toDeclared(),
        );
    }

    /**
     * The action's own validation rules, if it declares any.
     *
     * @param  class-string  $class
     * @return array<array-key, string|array<int, mixed>>
     */
    protected function rulesOf(string $class): array
    {
        return method_exists($class, 'rules')
            ? $this->requests->rulesFor($class)
            : [];
    }

    /** @param class-string $class */
    protected function describe(string $class): string
    {
        return Str::ucfirst(Str::lower(Str::headline(class_basename($class)))).'.';
    }

    /**
     * An action is a use case, so what it does is as opaque as a job. The
     * declaration decides, and nothing is inferred from the class name.
     */
    protected function safety(): Safety
    {
        return new Safety(readOnly: false, destructive: false, idempotent: false, openWorld: false);
    }
}
