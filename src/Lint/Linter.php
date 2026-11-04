<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Lint;

use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Attributes\AllowFields;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\McpInput;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Attributes\McpQueue;
use KayedSpace\McpBridge\Attributes\McpTimeout;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Attributes\Paginate;
use KayedSpace\McpBridge\Attributes\QueryModel;
use KayedSpace\McpBridge\Contracts\ReadsDocumentation;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Discovery\AttributeReader;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Security\AuthorityRequirement;
use KayedSpace\McpBridge\Sources\SourceRegistry;

/**
 * Checks what this application would expose, before an agent does.
 *
 * The lint reads the sources rather than the compiled manifest, because the
 * question it answers is whether the declarations are right, and a stale
 * manifest would answer a different one.
 *
 * Everything it reports is developer-facing. Nothing here reaches an agent.
 */
class Linter
{
    public function __construct(
        protected SourceRegistry $sources,
        protected ReadsDocumentation $documentation,
        protected AttributeReader $attributes,
        protected PromptScanner $prompts,
        protected AuthorityRequirement $authority,
        protected ManifestCompiler $compiler,
    ) {}

    /**
     * @param  bool  $all  include the deliberate choices: what is not opted in, and what is ignored
     * @return list<Finding>
     */
    public function lint(bool $all = false, ?string $only = null): array
    {
        $findings = [];
        $claimed = [];
        $exposed = 0;

        foreach ($this->sources->enabled() as $key => $source) {
            if ($only !== null && $key !== $only) {
                continue;
            }

            foreach ($source->discover() as $candidate) {
                // The same two questions the compiler asks, in the same
                // order, so the lint can never say a tool is fine when the
                // compiler would refuse it.
                $refusals = [
                    ...$source->validate($candidate),
                    ...$this->authority->refusals($key, $candidate),
                ];

                if ($refusals === []) {
                    $refusals = $this->compiler->limitRefusals($source, $candidate);
                }

                if ($refusals !== []) {
                    $findings = [...$findings, ...$this->refused($candidate, $refusals, $all)];

                    continue;
                }

                $exposed++;
                $findings = [...$findings, ...$this->claim($source, $candidate, $claimed)];
                $findings = [...$findings, ...$this->exposed($source, $candidate)];
            }
        }

        if ($exposed === 0) {
            $findings[] = Finding::warning(
                'MCP_BRIDGE_LINT_EMPTY',
                'manifest',
                'This application exposes no tools.',
                'Add #[McpTool] to an action. mcp-bridge:tools --refused lists what was found and why it is not exposed.',
            );
        }

        return $findings;
    }

    /**
     * A candidate refused for being denied, or for lacking the authority a
     * non-HTTP source needs, is a declaration that will not work: the
     * developer meant to expose it. Not being opted in is a choice, so it is
     * only reported when asked for.
     *
     * @param  list<Refusal>  $refusals
     * @return list<Finding>
     */
    protected function refused(ToolCandidate $candidate, array $refusals, bool $all): array
    {
        $findings = [];

        foreach ($refusals as $refusal) {
            // Never a choice: it is only asked of a candidate that would
            // otherwise have been served, and somebody wrote the limit.
            if ($refusal->code === 'MCP_BRIDGE_INVALID_RATE_LIMIT') {
                $findings[] = Finding::error(
                    $refusal->code,
                    $candidate->id,
                    $refusal->message,
                    'Until it reads, the tool is not served, because served it would have no limit at all.',
                );

                continue;
            }

            // The hint asserts the developer meant to expose this, so ask
            // whether they did rather than taking the refusal's word for it.
            // A command is refused for missing authority whether or not
            // anyone opted it in, so without this check an application with
            // the command source on failed its own lint over every Artisan
            // command the framework ships.
            if ($refusal->code === 'MCP_BRIDGE_SOURCE_REFUSED' && $this->optedIn($candidate)) {
                $findings[] = Finding::error(
                    $refusal->code,
                    $candidate->id,
                    $refusal->message,
                    'It carries #[McpTool], so something has to change: the declaration or the configuration.',
                );

                continue;
            }

            if ($all) {
                $findings[] = Finding::note($refusal->code, $candidate->id, $refusal->message);
            }
        }

        return $findings;
    }

    /**
     * Whether someone asked for this candidate, as opposed to discovery
     * having found it. A refusal without an ask is the configured default
     * doing its job, which is not a finding.
     */
    protected function optedIn(ToolCandidate $candidate): bool
    {
        return array_key_exists(McpTool::class, $candidate->declarations);
    }

    /**
     * Two candidates that want one name: the compiler keeps the first and
     * refuses the second, and the lint names both, because the developer
     * meant to expose both.
     *
     * @param  array<string, string>  $claimed
     * @return list<Finding>
     */
    protected function claim(Source $source, ToolCandidate $candidate, array &$claimed): array
    {
        $name = $this->compiler->nameFor($source, $candidate);

        if (isset($claimed[$name])) {
            return [Finding::error(
                'MCP_BRIDGE_LINT_COLLISION',
                $candidate->id,
                "Both [{$claimed[$name]}] and [{$candidate->id}] compile to the tool name [{$name}].",
                'Give one of them #[McpTool(name: ...)].',
            )];
        }

        $claimed[$name] = $candidate->id;

        return [];
    }

    /** @return list<Finding> */
    protected function exposed(Source $source, ToolCandidate $candidate): array
    {
        $findings = [];

        if ($candidate->description === null || $candidate->description === '') {
            $findings[] = Finding::warning(
                'MCP_BRIDGE_LINT_NO_DESCRIPTION',
                $candidate->id,
                'This tool has no description, so one is generated from its name and target.',
                'An agent picks a tool and fills its arguments from that text. Write it.',
            );
        }

        return [
            ...$findings,
            ...$this->divergence($source, $candidate),
            ...$this->interactive($candidate),
            ...$this->advisoryTimeout($source, $candidate),
            ...$this->unvalidatedWrite($source, $candidate),
            ...$this->unreadAttributes($source, $candidate),
        ];
    }

    /**
     * Which sources read each attribute. An attribute that nothing checks is
     * worse than none, because the developer believes it took effect.
     */
    protected const READ_BY = [
        McpInput::class => ['route', 'model'],
        McpQueue::class => ['job'],
        QueryModel::class => ['route', 'model'],
        AllowFilter::class => ['route', 'model'],
        AllowSort::class => ['route', 'model'],
        AllowInclude::class => ['route', 'model'],
        AllowFields::class => ['route', 'model'],
        Paginate::class => ['route', 'model'],
    ];

    /** @return list<Finding> */
    protected function unreadAttributes(Source $source, ToolCandidate $candidate): array
    {
        $findings = [];

        foreach (self::READ_BY as $attribute => $sources) {
            if (array_key_exists($attribute, $candidate->declarations) && ! in_array($source->key(), $sources, true)) {
                $findings[] = Finding::warning(
                    'MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE',
                    $candidate->id,
                    sprintf('#[%s] is read on %s tools and this is a %s tool, so it changes nothing here.', class_basename($attribute), implode(' and ', $sources), $source->key()),
                    'Remove it, or move the declaration to a source that reads it.',
                );
            }
        }

        $tool = $candidate->declarations[McpTool::class] ?? null;
        $operations = is_array($tool) ? ($tool['operations'] ?? $tool[3] ?? []) : [];
        $description = is_array($tool) ? ($tool['description'] ?? $tool[2] ?? null) : null;

        if ($operations !== [] && $source->key() !== 'model') {
            $findings[] = Finding::warning(
                'MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE',
                $candidate->id,
                '#[McpTool(operations:)] narrows a model\'s operations and this is not a model, so it changes nothing here.',
                'Remove it.',
            );
        }

        if ($description !== null && $source->key() === 'model') {
            $findings[] = Finding::warning(
                'MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE',
                $candidate->id,
                'A model produces one tool per operation, each describing itself, so #[McpTool(description:)] on a model is not read.',
                'Remove it; the operation names what the tool does.',
            );
        }

        // A param that names a field no source produced is a param on the
        // wrong path, except on a route, where it adds the field. A model's
        // params are checked against the model rather than one operation:
        // `owner_id` exists on create and not on list, and both are the
        // same declaration.
        if ($source->key() !== 'route') {
            $schema = $source->schema($candidate);
            $class = $candidate->target instanceof ClassTarget ? $candidate->target->class : null;
            $fillable = $source->key() === 'model' && $class !== null && is_subclass_of($class, Model::class)
                ? (new $class)->getFillable()
                : [];

            foreach ($candidate->declared->params ?? [] as $param) {
                if ($schema->get($param['name']) === null && ! in_array($param['name'], $fillable, true)) {
                    $findings[] = Finding::warning(
                        'MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE',
                        $candidate->id,
                        sprintf('#[McpParam(\'%s\')] names a field this tool does not have, so it changes nothing here.', $param['name']),
                        'Check the path, or remove it.',
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * A timeout is a budget, and only an HTTP client can hold a call to one.
     * A route, command, job or action runs in this process, where a declared
     * timeout shapes the description and the idempotency window and stops
     * nothing. Saying so here keeps a developer from reading the attribute
     * as a guarantee.
     *
     * @return list<Finding>
     */
    protected function advisoryTimeout(Source $source, ToolCandidate $candidate): array
    {
        if ($source->key() === 'openapi' || ! array_key_exists(McpTimeout::class, $candidate->declarations)) {
            return [];
        }

        return [Finding::note(
            'MCP_BRIDGE_LINT_TIMEOUT_ADVISORY',
            $candidate->id,
            'The timeout is advisory here: it tells the agent what to expect and bounds the idempotency window, and the call is not interrupted.',
            'Keep the work short, or queue it as a job and let job_status answer.',
        )];
    }

    /**
     * A model write built from `$fillable` and the casts has the database as
     * its only validator, and a database says what it refuses after the
     * fact, in words an agent cannot act on.
     *
     * @return list<Finding>
     */
    protected function unvalidatedWrite(Source $source, ToolCandidate $candidate): array
    {
        $operation = $candidate->meta['operation'] ?? null;

        if ($source->key() !== 'model' || ! in_array($operation, ['create', 'update'], true)) {
            return [];
        }

        if (($candidate->meta['rules'] ?? null) !== null) {
            return [];
        }

        return [Finding::warning(
            'MCP_BRIDGE_LINT_NO_RULES',
            $candidate->id,
            'This write validates nothing beyond what the database enforces.',
            'Name a FormRequest or a Data class with #[McpInput] on the model, so the schema says what is required and a bad value is refused with a reason.',
        )];
    }

    /**
     * Where a documentation attribute and an MCP attribute describe the same
     * field differently, the MCP attribute wins for the agent and the
     * documentation wins for the document, which means the REST docs and the
     * agent schema are telling two different stories about one parameter. One
     * of them is wrong, and which one is not this package's call.
     *
     * @return list<Finding>
     */
    protected function divergence(Source $source, ToolCandidate $candidate): array
    {
        $class = $candidate->meta['controller'] ?? null;
        $action = $candidate->meta['action'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            return [];
        }

        $documented = $this->documentation->read($class, is_string($action) ? $action : null);

        if ($documented === null || $documented->input->isEmpty()) {
            return [];
        }

        $declared = $this->attributes->read($class, is_string($action) ? $action : null);
        $findings = [];

        foreach ($declared->params as $param) {
            $field = $documented->input->get($param->name);

            if ($field === null) {
                continue;
            }

            foreach ($this->disagreements($param->name, $param->description, $param->type, $field) as $message) {
                $findings[] = Finding::warning(
                    'MCP_BRIDGE_LINT_DIVERGENCE',
                    $candidate->id,
                    $message,
                    'The MCP attribute wins for the agent. Fix whichever one is out of date.',
                );
            }
        }

        return [...$findings, ...$this->hiddenDocumentation($documented->input, $source->schema($candidate), $candidate)];
    }

    /** @return list<string> */
    protected function disagreements(string $path, ?string $description, ?string $type, Field $documented): array
    {
        $messages = [];

        if ($description !== null && $documented->description !== null && $description !== $documented->description) {
            $messages[] = "[{$path}] is described as \"{$documented->description}\" in the API documentation "
                ."and \"{$description}\" for agents.";
        }

        if ($type !== null && $type !== $documented->type->value) {
            $messages[] = "[{$path}] is a {$documented->type->value} in the API documentation and a {$type} for agents.";
        }

        return $messages;
    }

    /**
     * A parameter the API documentation advertises but the agent never sees,
     * because something hid it: #[IgnoreParam], #[McpParam(hidden: true)] or
     * the fill_from_auth list.
     *
     * Usually deliberate, which is why it is a note. Worth printing because
     * the public documentation and the tool schema now promise different
     * things, and the reader of the docs cannot tell.
     *
     * @return list<Finding>
     */
    protected function hiddenDocumentation(InputSchema $documented, InputSchema $schema, ToolCandidate $candidate): array
    {
        $findings = [];

        foreach ($documented->fields as $path => $field) {
            if ($schema->get($path)?->hidden !== true) {
                continue;
            }

            $name = $field->wire->name ?? $path;

            $findings[] = Finding::note(
                'MCP_BRIDGE_LINT_HIDDEN_DOC',
                $candidate->id,
                "The API documentation declares [{$name}], which agents never see because it is hidden.",
                'Deliberate if it was ignored or filled from the authenticated user.',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    protected function interactive(ToolCandidate $candidate): array
    {
        $class = $candidate->meta['command'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            return [];
        }

        $findings = [];

        foreach ($this->prompts->scan($class) as $prompt) {
            $findings[] = Finding::error(
                'MCP_BRIDGE_LINT_INTERACTIVE',
                $candidate->id,
                "This command calls {$prompt['method']}() at {$class}:{$prompt['line']}, and an agent cannot answer a prompt.",
                'Add an option for it, or keep the command out of the tool list with #[McpIgnore].',
            );
        }

        return $findings;
    }
}
