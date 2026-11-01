# Attributes

Every attribute this package declares lives in `KayedSpace\McpBridge\Attributes`. For each one this page gives its targets, its parameters and **what reads it**, because an attribute that nothing checks is worse than no attribute.

Where a class and a method both carry an attribute, the method wins for every single-valued declaration, and repeatable ones (`#[McpScope]`, `#[McpAbility]`, `#[McpGroup]`, `#[McpParam]`, `#[McpExample]`) accumulate from both, class first.

This package never redeclares another package's attribute: `dedoc/scramble` and `laravel/mcp` attributes are read by name, so an uninstalled package cannot break discovery.

| Group | Attributes |
| --- | --- |
| Identity | [`McpTool`](#mcptool), [`McpIgnore`](#mcpignore), [`McpGroup`](#mcpgroup) |
| Authority | [`McpScope`](#mcpscope), [`McpAbility`](#mcpability), [`McpConfirm`](#mcpconfirm) |
| Limits | [`McpRateLimit`](#mcpratelimit), [`McpTimeout`](#mcptimeout), [`McpQueue`](#mcpqueue) |
| Schema | [`McpParam`](#mcpparam), [`McpInput`](#mcpinput) |
| Output | [`McpOutput`](#mcpoutput), [`McpExample`](#mcpexample), [`McpDeprecated`](#mcpdeprecated) |
| List queries | [`QueryModel`](#querymodel), [`AllowFilter`](#allowfilter), [`AllowSort`](#allowsort), [`AllowInclude`](#allowinclude), [`AllowFields`](#allowfields), [`Paginate`](#paginate) |
| From `laravel/mcp` | [`IsReadOnly`, `IsDestructive`, `IsIdempotent`, `IsOpenWorld`](#from-laravel-mcp-safety-annotations) |

## Identity

### McpTool

```php
#[McpTool(?string $name = null, ?string $title = null, ?string $description = null, array $operations = [])]
```

Targets: class, method. Opts something in as a tool and overrides how the agent sees it.

| Parameter | Effect |
| --- | --- |
| `name` | The explicit tool name. Wins over every derived name and skips the source prefix. A `routes.<name>.name` entry beats it, sanitised the same way, and `modules.always_prefix_module` still applies to both. |
| `title` | A human title. Defaults to the headline of the name. |
| `description` | What the agent reads. Falls back to documentation, then to a generated sentence. |
| `operations` | On a model, which of `list`, `get`, `create`, `update`, `delete` this model offers. Narrows `sources.model.operations` and never widens it: an operation named here that the configuration does not enable is refused with the reason, visible in `mcp-bridge:tools --refused`. |

On a controller class it applies to every action; on a method it overrides the class. On a command, job or action class it is what opts that item in, and the class is the only level there is. On a model it opts the model in and may narrow its operations; a model's tool names are generated and prefixed whatever `name` says, `title` is read, and `description` is not, because each operation describes itself (the lint says so).

Read by: `AttributeReader`, then every source's `validate()` (an opted-in item passes the opt-in check), `ToolNamer`, `ManifestCompiler` for the description and title, `ModelSource` for `operations`.

### McpIgnore

```php
#[McpIgnore]
```

Targets: class, method. Excludes from discovery in any mode. Wins over every opt-in. The configured exclusions work the same way from the other side: a route `discovery.exclude` matches, or a command on `sources.command.deny`, is gone before `#[McpTool]` is read, because an allowlist is not liftable from the code it keeps out.

Read by: each source's `validate()`, which refuses the candidate with `MCP_BRIDGE_IGNORED`. Also what to use on a command that prompts.

### McpGroup

```php
#[McpGroup(string $group)]
```

Targets: class, method; repeatable. Assigns the tool to a group.

Read by: `ManifestCompiler`, which records it on the `ToolDefinition` and uses it to pick the server: a tool whose group is listed in a server's `groups` is served from that endpoint before `sources.<key>.server` and `modules.per_module_servers` are consulted, so one route or one command can move without moving every route or command. The code names a concept; `servers.<key>.groups` maps it to an endpoint. `*` in a server's `groups` claims nothing. See [where a tool lands](/guide/servers-and-modules#where-a-tool-lands) for the full order.

## Authority

### McpScope

```php
#[McpScope(string $scope)]
```

Targets: class, method; repeatable. Requires a token scope. Every listed scope must hold.

Checked twice: when listing, so the tool is hidden, and when calling, because hiding a tool is not enforcement. **Mandatory together with `#[McpAbility]` on every source that is not an HTTP route.**

Read by: `AuthorityRequirement` at compile time (refuses a non-route candidate with none), and `ScopeGate` on listing and on every call, through `TokenScopes`, which asks the user's package token or else its `tokenCan()`. The scopes also appear in the tool description ("Requires the scope ...").

### McpAbility

```php
#[McpAbility(string $ability)]
```

Targets: class, method; repeatable. Requires a Gate ability of the resolved actor, so your application's own Gate answers "who may", not this package.

Read by: `AuthorityRequirement` and `ScopeGate` (`Gate::forUser($actor)->allows($ability)`), on listing and on every call. For jobs, `ReauthorizesJob` asks the Gate again when the queued job runs.

### McpConfirm

```php
#[McpConfirm(?string $message = null)]
```

Targets: class, method. Asks the user before this runs. The protocol is stateless, so confirmation is a handle the agent passes back on a second call, and the handle carries its own contents.

Read by: `AuthorityRequirement::authority()`, which turns confirmation on regardless of `security.destructive`, and `ScopeGate`, which uses `message` as the preview text. The handle is minted by `ConfirmationHandles`. Destructive tools confirm without this attribute when `security.destructive` is `confirm`.

## Limits

### McpRateLimit

```php
#[McpRateLimit(int $attempts, int $perMinutes = 1, ?int $perSeconds = null)]
```

Targets: class, method. A per-tool limit for agent traffic, separate from human API limits. `#[McpRateLimit(10)]` is `10/minute`; `#[McpRateLimit(100, 60)]` is `100/60minutes`; `#[McpRateLimit(5, perSeconds: 10)]` is `5/10seconds`.

Read by: `ManifestCompiler` into the tool's `Limits`, and `ScopeGate`, which counts per tool per actor. Overrides `sources.<key>.rate_limit`; a `routes.<name>.rate_limit` entry overrides it.

### McpTimeout

```php
#[McpTimeout(int $seconds)]
```

Targets: class, method. How long a call may take, in seconds, overriding the per-source default.

Read by: `ManifestCompiler`, which records it and adds "Expect it to take up to N seconds." to the description; `IdempotencyKeys`, which holds a key in flight for that long; and `Linter`, which notes it as `MCP_BRIDGE_LINT_TIMEOUT_ADVISORY` on every in-process source (route, command, job, action and model).

It interrupts nothing. An in-process call runs to the end, and the budget only tells the agent what to expect. For work that needs a hard limit, queue it as a job and let `job_status` answer. Long calls are visible in the audit trail's `duration_ms`.

An OpenAPI or remote tool has no PHP class, so it carries no attributes. `OpenApiExecutor` gives the HTTP client the document block's `timeout`, and otherwise `sources.openapi.timeout` (default `30`).

### McpQueue

```php
#[McpQueue(?string $queue = null, ?string $connection = null, bool $sync = false, ?int $delay = null)]
```

Targets: class, method. How a job tool's work is queued. `queue` and `connection` choose where it is dispatched and override `sources.job.queue`, `delay` is in seconds, and `sync: true` runs the job inside the call.

Read by: `JobExecutor`, and `JobSource`, whose description says when a job runs inside the call. On any other source it is not read, and `mcp-bridge:lint` reports it as `MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE`.

## Schema

### McpParam

```php
#[McpParam(string $name, ?string $description = null, mixed $example = null, array $enum = [], ?string $type = null, bool $hidden = false, ?bool $fromAuth = null)]
```

Targets: class, method; repeatable. Describes, retypes, constrains, hides or fills one input field by its dot path, on every source; on a route it can also add one. The most specific layer of the schema merge, so it beats rules, route parameters and documentation attributes alike.

| Parameter | Effect |
| --- | --- |
| `name` | The dot path of the field. |
| `description`, `example`, `enum` | Replace the inferred ones. |
| `type` | A field type name; an unknown name is ignored. |
| `hidden` | Keep the field in the schema for the executor but never show it to the agent or take it from the agent. The value is dropped, not filled. |
| `fromAuth` | The per-tool form of `security.fill_from_auth`. `true` hides the field and, on a create, fills it with the acting user's id; an update leaves it, and a remote or OpenAPI tool drops it. `false` keeps a name the global list hides in the agent's hands for this tool, which is what an admin tool that assigns records to other users needs. |

Read by: `SchemaBuilder::applyParams()` for route tools, where a param may add a field; `ManifestCompiler::applyParams()` for every source, shaping a field the source produced; and `Linter`, which compares it with documentation attributes for `MCP_BRIDGE_LINT_DIVERGENCE` and reports a param naming a field a non-route tool does not have as `MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE`.

### McpInput

```php
#[McpInput(string $request)]
```

Targets: class, method. Names the FormRequest whose rules describe this tool's input, for actions that validate inline or type-hint a plain `Request`. A `spatie/laravel-data` class works in its place: its property types and validation attributes are read through `getValidationRules()`.

Read by: `SchemaBuilder::rulesFor()` for routes, and `ModelSource` and `ModelSchema` for model `create` and `update`, all through `FormRequestReader::rulesFor()`. An action declares its rules in its own `rules()` method, and a job or command has nothing a FormRequest could describe, so on those sources this attribute is not read and the lint says so.

## Output

### McpOutput

```php
#[McpOutput(array $fields = [], ?int $maxItems = null)]
```

Targets: class, method. Shapes and trims what the agent receives. `fields` keeps only these keys of each record. `maxItems` caps a list, overriding `output.max_items` for this tool both on the way out and in the `per_page` the agent may ask for.

Read by: `OutputShaper` (selection and trimming), `QuerySchema` and `ModelSchema` (the `per_page` cap), and `GeneratedTool::outputSchema()`, which advertises the declared keys, typed when `integrations.scramble.responses` inferred them. The [built-in tools](/sources/#built-in-tools) (`upload_file`, `job_status`, `search_tools`, `execute_tools`) shape their own outputs and read no attributes.

### McpExample

```php
#[McpExample(array $input, ?string $description = null)]
```

Targets: class, method; repeatable. An example call folded into the tool description. One worked example is often worth more than another sentence of prose.

Read by: `ManifestCompiler::description()`, which appends `Example, <description>: <json>.`.

### McpDeprecated

```php
#[McpDeprecated(?string $message = null, ?string $until = null)]
```

Targets: class, method. Keeps a tool exposed but tells the agent to stop reaching for it, and what to use instead. `message` says what to use; `until` names the version or date the tool goes away, which the notice repeats so an agent can weigh the two.

Read by: `ManifestCompiler`, which appends the deprecation notice to the description.

## List queries

These describe a list endpoint's allowed query. See [list endpoints](/guide/list-endpoints) for how they combine.

Read by: `QueryAttributeReader` (layered under any Spatie chain and shared definition), `QuerySchema` (the agent's inputs), `ApiQuery` (applying the same declaration to the endpoint's real query) and `ScrambleParameters` (feeding OpenAPI). Applies to route tools, and on a model class to the model's own `list` and `get` tools through `ModelSchema`, where they layer over what the columns infer. On a job, action or command nothing reads them, and the lint says so.

### QueryModel

```php
#[QueryModel(string $model, ?string $resource = null)]
```

Targets: class, method. The model a list endpoint queries, which is what Spatie's `QueryBuilder::for()` is given. Declared when the package should read filters, sorts, includes and fields from attributes rather than from a fluent chain. `resource` is the `fields[...]` key for sparse fieldsets when it is not the model's table.

### AllowFilter

```php
#[AllowFilter(string $name, ?string $type = null, ?string $column = null, array|string $enum = [], ?string $description = null, mixed $example = null, bool $multiple = false)]
```

Targets: class, method; repeatable. One allowed filter, mirroring Spatie's `AllowedFilter` vocabulary.

| Parameter | Effect |
| --- | --- |
| `name` | The public filter name the agent sends. |
| `type` | `partial` (the default), `exact`, `beginsWith`, `endsWith`, `scope`, `callback`, `custom`, `trashed`, `belongsTo` or `operator`. Left unstated on an enriching attribute, the chain's type is kept. |
| `column` | The real column when the public name is an alias. Never exposed to the agent. |
| `enum` | A backed enum class or the values themselves. |
| `description`, `example` | Shown to the agent. |
| `multiple` | The filter accepts a list. |

### AllowSort

```php
#[AllowSort(string $name, ?string $default = null, ?string $column = null, ?string $using = null, ?string $description = null)]
```

Targets: class, method; repeatable. One allowed sort. `default` (`asc` or `desc`) makes it the sort applied when the agent names none, which is Spatie's `defaultSort()`. `column` is the database column when the sort name differs from it. `using` is a Spatie `Sort` class for a custom sort. `description` is shown to the agent beside the value.

### AllowInclude

```php
#[AllowInclude(string|array $names, ?string $type = null, ?string $relation = null, ?string $column = null, ?string $description = null, ?string $ability = null)]
```

Targets: class, method; repeatable. Allowed includes. `names` is one name or a list. `type` is `relation` (the default), `count`, `exists`, `min`, `max`, `sum` or `avg`. `relation` and `column` locate an aggregate include. Limited per call by `query.max_includes` and `query.max_include_depth`.

`ability` names a Gate ability the actor must hold for the include to exist for them, checked by `ApiQuery` on every call, on the Spatie path and the Eloquent one alike. For an actor the Gate denies, the include is refused as not allowed and the message lists the ones they may have. The schema still lists it, because the manifest is compiled once for every caller.

On a model class, read by `ModelSchema` for the model's own `list` and `get` tools: the list loads the include with `with()` and `get` with `load()`, after the policy answered about the record. A relation in the model's `$hidden` is dropped from the offer whatever this attribute says. See [the model source](/sources/model#relations-are-opt-in).

### AllowFields

```php
#[AllowFields(string|array $fields, ?string $resource = null)]
```

Targets: class, method; repeatable. Sparse fieldsets: which columns an agent may ask for. `resource` is the `fields[...]` key, which defaults to the queried model's table.

### Paginate

```php
#[Paginate(int $perPage = 15, ?int $max = null, string $type = 'length-aware', bool $fixed = false)]
```

Targets: class, method. How a list paginates, which decides whether the agent gets `page` and `per_page` or a `cursor`.

| Parameter | Effect |
| --- | --- |
| `perPage` | The default page size. |
| `max` | The largest page the agent may ask for, further capped by `output.max_items`. |
| `type` | `length-aware`, `simple` or `cursor`. `cursor` gives the agent a `cursor` input instead of `page`. |
| `fixed` | The page size is written into the call (`->paginate(15)`) and cannot be changed, so the agent gets `page` but no `per_page`: an input the application ignores is worse than no input. |

## From `laravel/mcp`: safety annotations

`Laravel\Mcp\Server\Tools\Annotations\IsReadOnly`, `IsDestructive`, `IsIdempotent` and `IsOpenWorld` are consumed, never redeclared. They override the safety inferred from the HTTP verb, the command default or the model operation, and may be written on a controller method even though `laravel/mcp` declares them for classes. `#[IsDestructive(false)]` is read as an explicit "not destructive". All four hints are always sent explicitly to clients, because a client reads a missing hint as its worst case.

Read by: `AttributeReader`, then `Safety::with()` in each source and `ManifestCompiler`. A read-only declaration without an explicit destructive one clears an inferred destructive flag.

## Dedoc Scramble and Scribe

Read as documentation, never redeclared: Scramble's `QueryParameter`, `BodyParameter`, `PathParameter`, `HeaderParameter`, `Endpoint`, `Group`, `IgnoreParam`, `ExcludeRouteFromDocs` and `ExcludeAllRoutesFromDocs`, and Scribe's `@queryParam`, `@bodyParam`, `@urlParam`, `@group` and `@hideFromAPIDocumentation` docblock tags. See [documentation packages](/guide/documentation-packages).
