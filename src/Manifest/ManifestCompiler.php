<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Manifest;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\Authority;
use KayedSpace\McpBridge\Data\Declared;
use KayedSpace\McpBridge\Data\Deprecation;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Limits;
use KayedSpace\McpBridge\Data\Output;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\RouteTarget;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Discovery\ToolNamer;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Execution\IdempotencyKeys;
use KayedSpace\McpBridge\Integrations\ScrambleResponses;
use KayedSpace\McpBridge\Modules\ModuleRegistry;
use KayedSpace\McpBridge\Security\AuthorityRequirement;
use KayedSpace\McpBridge\Sources\SourceRegistry;

/**
 * Turns candidates into the compiled manifest: naming, exposure, authority,
 * limits, shaping and server assignment, once, at build time.
 */
class ManifestCompiler
{
    public function __construct(
        protected SourceRegistry $sources,
        protected AuthorityRequirement $authority,
        protected ModuleRegistry $modules,
        protected Repository $config,
        protected IdempotencyKeys $idempotency,
        protected ScrambleResponses $responses,
        protected ToolNamer $namer,
    ) {}

    public function compile(): CompileResult
    {
        $definitions = [];
        $refusals = [];
        $claimed = [];

        foreach ($this->sources->enabled() as $source) {
            foreach ($source->discover() as $candidate) {
                $reasons = [
                    ...$source->validate($candidate),
                    ...$this->authority->refusals($source->key(), $candidate),
                ];

                // Asked only of a candidate nothing else refused, so a bad
                // source-wide default is reported once per tool it would
                // have limited, not once per Artisan command the framework
                // ships and nobody opted in.
                if ($reasons === []) {
                    $reasons = $this->limitRefusals($source, $candidate);
                }

                if ($reasons !== []) {
                    $refusals[$candidate->id] = $reasons;

                    continue;
                }

                $module = $this->module($candidate);
                $name = $this->nameFor($source, $candidate, $module);

                // One refusal rather than a failed build: a collision between
                // a route and the model behind it is the ordinary case the
                // moment the model source is turned on, and it should cost
                // that one tool, visibly, not every tool.
                if (isset($claimed[$name])) {
                    $refusals[$candidate->id] = [Refusal::nameTaken($candidate->id, $name, $claimed[$name])];

                    continue;
                }

                $claimed[$name] = $candidate->id;
                $definitions[] = $this->definition($source, $candidate, $name, $module);
            }
        }

        ksort($refusals);

        return new CompileResult(definitions: $definitions, refusals: $refusals);
    }

    /**
     * The name a candidate compiles to: the route override, else the
     * source's suggestion with the source prefix and the module prefix.
     *
     * Public because the lint asks the same question, and a lint that
     * named tools its own way reported collisions the compiler never made.
     */
    public function nameFor(Source $source, ToolCandidate $candidate, ?string $module = null): string
    {
        $override = $this->override($candidate, 'name');

        // A configured name is still a tool name: sanitised and module
        // prefixed like one the code suggested, so the two layers differ in
        // where the name comes from and in nothing else.
        $name = $override === null
            ? $this->name($source, $candidate)
            : $this->namer->sanitised($override);

        return $this->modules->prefix($name, $module ?? $this->module($candidate));
    }

    protected function name(Source $source, ToolCandidate $candidate): string
    {
        $suggested = $candidate->suggestedName;

        $name = $suggested === null || $suggested === ''
            ? Str::snake(Str::replace([':', ' ', '/'], '_', $candidate->id))
            : $suggested;

        // A name someone wrote down is the name; a derived one takes the
        // source's prefix, which is what keeps a model's tools from wanting
        // the names of the routes in front of the same model. Only a name
        // the source used counts as written down: a model names one tool
        // per operation and cannot take a single name, so its tools stay
        // prefixed whatever #[McpTool] says.
        $explicit = $this->explicitName($candidate);

        return $explicit !== null && $this->namer->sanitised($explicit) === $name
            ? $name
            : $this->namer->prefixed($name, $source->key());
    }

    protected function explicitName(ToolCandidate $candidate): ?string
    {
        $arguments = $candidate->declarations[McpTool::class] ?? null;
        $name = is_array($arguments) ? ($arguments['name'] ?? $arguments[0] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Which module a tool came from, decided once here rather than in each
     * source: ownership is a property of where the code lives, and every
     * source already says which class that was.
     */
    protected function module(ToolCandidate $candidate): ?string
    {
        if ($candidate->module !== null) {
            return $candidate->module;
        }

        foreach (['controller', 'command', 'class'] as $key) {
            $class = $candidate->meta[$key] ?? null;

            if (is_string($class) && class_exists($class)) {
                return $this->modules->forClass($class);
            }
        }

        return null;
    }

    protected function definition(Source $source, ToolCandidate $candidate, string $name, ?string $module): ToolDefinition
    {
        $declared = $candidate->declared ?? new Declared;

        $safety = ($candidate->safety ?? new Safety(
            readOnly: false,
            destructive: false,
            idempotent: false,
            openWorld: false,
        ))->with($declared->safety);

        $deprecation = $declared->isDeprecated()
            ? new Deprecation(message: $declared->deprecatedMessage, until: $declared->deprecatedUntil)
            : null;

        $output = new Output(
            fields: $declared->outputFields,
            maxItems: $declared->outputMaxItems,
            schema: $this->responses->read($candidate),
        );
        $authority = $this->authority->authority($candidate);

        return new ToolDefinition(
            name: $name,
            candidateId: $candidate->id,
            source: $source->key(),
            description: $this->description($candidate, $name, $safety, $declared, $deprecation),
            target: $candidate->target,
            input: $this->input($source, $candidate, $authority, $safety, $declared),
            safety: $safety,
            authority: $authority,
            limits: $this->limits($source, $declared, $candidate),
            server: $this->claimedServer($candidate) ?? $this->modules->server($module, $this->server($source->key(), $candidate)),
            module: $module,
            title: $candidate->title,
            groups: $candidate->groups === []
                ? $this->modules->groups($module)
                : $candidate->groups,
            origin: [
                'name' => match (true) {
                    $this->override($candidate, 'name') !== null => 'config',
                    $candidate->suggestedName === null => 'candidate-id',
                    default => 'source',
                },
                'description' => $candidate->description === null ? 'generated' : 'attribute',
                'safety' => $declared->safety === [] ? 'inferred' : 'attribute',
                'rate_limit' => match (true) {
                    $this->override($candidate, 'rate_limit') !== null => 'config',
                    $declared->rateLimit !== null => 'attribute',
                    default => 'source-default',
                },
            ],
            output: $output->isEmpty() ? null : $output,
            deprecated: $deprecation,
            queue: $declared->queue,
        );
    }

    /**
     * A tool that needs confirmation advertises the handle as an input, so an
     * agent can see how to confirm. The field carries no wire, so it is never
     * forwarded to the code behind the tool.
     */
    protected function input(Source $source, ToolCandidate $candidate, Authority $authority, Safety $safety, Declared $declared): InputSchema
    {
        // A remote server or an API this application calls knows nothing of
        // this application's users, so a field hidden there is dropped
        // rather than filled with an id that belongs to someone else on that
        // system.
        $fill = in_array($source->key(), ['remote', 'openapi'], true) ? null : 'auth';

        $schema = $this->applyParams($this->hideAuthFilled($source->schema($candidate), $declared, $fill), $declared, $fill);

        // A write can be retried by an agent that never saw the first answer,
        // so it is offered a key to make the retry safe. Not on a read, where
        // running twice costs nothing but time.
        if ($safety->readOnly === false && $this->idempotency->enabled()) {
            $schema = $schema->with(new Field(
                path: IdempotencyKeys::INPUT,
                type: FieldType::String,
                description: 'An optional key of your own. Send the same key if you retry this call, '.
                    'and it will answer with the first result instead of doing the work again.',
                origin: ['idempotency'],
            ));
        }

        if (! $authority->confirm) {
            return $schema;
        }

        return $schema->with(new Field(
            path: 'confirm',
            type: FieldType::String,
            description: 'The confirmation handle from a previous call to this tool. '.
                'Call without it first, show the user what would happen, then call again with it.',
            origin: ['confirmation'],
        ));
    }

    /**
     * Fields the application fills from the authenticated user, hidden for
     * every source rather than for routes alone.
     *
     * This was the tail of the route schema builder, which meant the same
     * field was hidden from an agent calling a route and offered to it on the
     * same record's model tool: `user_id` in `$fillable` was an input an
     * agent could set, and a policy that authorises "may create a course"
     * never gets asked which user the course would belong to.
     */
    protected function hideAuthFilled(InputSchema $schema, Declared $declared, ?string $fill): InputSchema
    {
        $configured = array_values(array_filter(
            $this->config->array('mcp-bridge.security.fill_from_auth', []),
            'is_string',
        ));

        // #[McpParam(fromAuth: false)] keeps a globally listed name in the
        // agent's hands for this one tool; `true` is applied with the rest
        // of the params below.
        $exempt = [];

        foreach ($declared->params as $param) {
            if ($param['from_auth'] === false) {
                $exempt[] = $param['name'];
            }
        }

        return $schema->hiding(array_values(array_diff($configured, $exempt)), 'fill_from_auth', $fill);
    }

    /**
     * #[McpParam] on any source: describe, retype, hide or fill a field the
     * source produced. Adding a field belongs to the route schema builder,
     * which knows the wire; here a param that names nothing is left alone
     * and the lint reports it.
     */
    protected function applyParams(InputSchema $schema, Declared $declared, ?string $fill): InputSchema
    {
        foreach ($declared->params as $param) {
            $existing = $schema->get($param['name']);

            if ($existing === null) {
                continue;
            }

            $type = $param['type'] === null ? null : FieldType::tryFrom($param['type']);

            $field = $existing->with(
                type: $type,
                description: $param['description'],
                enum: $param['enum'] === [] ? null : $param['enum'],
                examples: $param['example'] === null ? null : [$param['example']],
                hidden: $param['hidden'] || $existing->hidden,
                origin: in_array('attribute:McpParam', $existing->origin, true) ? null : [...$existing->origin, 'attribute:McpParam'],
            );

            if ($param['from_auth'] === true) {
                $field = $field->hidden('attribute:McpParam', $fill);
            }

            $schema = new InputSchema([...$schema->fields, $param['name'] => $field]);
        }

        return $schema;
    }

    /**
     * Agents pick a tool and fill its arguments from the description, so it
     * carries the safety note, the scopes it needs, any worked examples and
     * the deprecation notice, not just the summary.
     */
    protected function description(
        ToolCandidate $candidate,
        string $name,
        Safety $safety,
        Declared $declared,
        ?Deprecation $deprecation,
    ): string {
        $summary = $candidate->description !== null && $candidate->description !== ''
            ? $candidate->description
            : Str::ucfirst(Str::replace('_', ' ', $name)).'. '.$candidate->target->describe().'.';

        $parts = [$summary];

        if ($safety->destructive) {
            $parts[] = 'Deletes data.';
        } elseif ($safety->readOnly) {
            $parts[] = 'Read-only.';
        }

        if ($declared->scopes !== []) {
            $parts[] = 'Requires the scope '.implode(' and ', $declared->scopes).'.';
        }

        // An agent deciding whether to wait needs to know how long this may
        // take, and the description is the only place it reads. Phrased as
        // an expectation: in-process sources do not cut a call off at it.
        if ($declared->timeout !== null) {
            $parts[] = "Expect it to take up to {$declared->timeout} seconds.";
        }

        foreach ($declared->examples as $example) {
            $input = json_encode($example['input']);
            $label = $example['description'] === null ? 'Example' : "Example, {$example['description']}";

            $parts[] = "{$label}: ".($input === false ? '{}' : $input).'.';
        }

        if ($deprecation !== null) {
            $parts[] = $deprecation->notice();
        }

        return implode(' ', $parts);
    }

    /**
     * A candidate whose rate limit cannot be read is refused, because served
     * it would have no limit at all. One refusal rather than a failed build,
     * as for a name collision: it costs that tool, visibly, not every tool.
     *
     * Public because the lint asks the same question, and a lint that read
     * limits its own way could pass what the compiler refuses.
     *
     * @return list<Refusal>
     */
    public function limitRefusals(Source $source, ToolCandidate $candidate): array
    {
        $rateLimit = $this->rateLimit($source, $candidate->declared ?? new Declared, $candidate);

        if ($rateLimit === null || Limits::parseRate($rateLimit['value']) !== null) {
            return [];
        }

        return [Refusal::invalidRateLimit($candidate->id, $rateLimit['value'], $rateLimit['setting'])];
    }

    protected function limits(Source $source, Declared $declared, ToolCandidate $candidate): Limits
    {
        return new Limits(
            timeout: $declared->timeout ?? $this->config->integer("mcp-bridge.sources.{$source->key()}.timeout", 30),
            rateLimit: $this->rateLimit($source, $declared, $candidate)['value'] ?? null,
        );
    }

    /**
     * The rate limit a candidate compiles to, and the setting it came from,
     * so a refusal can say where to fix it. A blank setting is no limit, as
     * it already was for a route's own entry.
     *
     * @return array{value: string, setting: string}|null
     */
    protected function rateLimit(Source $source, Declared $declared, ToolCandidate $candidate): ?array
    {
        $override = $this->override($candidate, 'rate_limit');

        if ($override !== null && $candidate->target instanceof RouteTarget) {
            return ['value' => $override, 'setting' => "routes.{$candidate->target->routeName}.rate_limit"];
        }

        if ($declared->rateLimit !== null) {
            return ['value' => $declared->rateLimit, 'setting' => '#[McpRateLimit]'];
        }

        $configured = $this->config->get("mcp-bridge.sources.{$source->key()}.rate_limit");

        return is_string($configured) && $configured !== ''
            ? ['value' => $configured, 'setting' => "sources.{$source->key()}.rate_limit"]
            : null;
    }

    /**
     * One route's own entry in `mcp-bridge.routes`, keyed by route name.
     *
     * The last layer of the merge, and the only one that does not require
     * touching the code it describes: renaming a tool or tightening its rate
     * limit in an application you cannot edit the controllers of.
     */
    protected function override(ToolCandidate $candidate, string $option): ?string
    {
        $target = $candidate->target;

        if (! $target instanceof RouteTarget || $target->routeName === null) {
            return null;
        }

        $entry = $this->config->array('mcp-bridge.routes', [])[$target->routeName] ?? null;
        $value = is_array($entry) ? ($entry[$option] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The server whose `groups` claims one of this tool's groups, if any.
     *
     * Asked before the source's server and before a module's, so
     * #[McpGroup('ops')] on one route moves that route alone, and a command
     * whose source lives on `ops` can still be placed by its group. The code
     * names a concept and the configuration maps it to an endpoint; `*`
     * claims nothing, it means "whatever arrives by source".
     */
    protected function claimedServer(ToolCandidate $candidate): ?string
    {
        foreach ($this->config->array('mcp-bridge.servers', []) as $key => $server) {
            $groups = is_array($server) ? ($server['groups'] ?? []) : [];
            $claimed = is_array($groups) ? array_filter($groups, 'is_string') : [];

            if (array_intersect($candidate->groups, $claimed) !== []) {
                return (string) $key;
            }
        }

        return null;
    }

    /** A source goes to its configured server, or to the one that lists it. */
    protected function server(string $sourceKey, ToolCandidate $candidate): string
    {
        $explicit = $this->config->get("mcp-bridge.sources.{$sourceKey}.server");

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        foreach ($this->config->array('mcp-bridge.servers', []) as $key => $server) {
            $sources = is_array($server) ? ($server['sources'] ?? []) : [];

            if (is_array($sources) && in_array($sourceKey, $sources, true)) {
                return (string) $key;
            }
        }

        return 'default';
    }
}
