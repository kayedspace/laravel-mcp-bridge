# Configuration

The package configuration is named `mcp-bridge`. The provider merges its defaults automatically, so publish it only when your application should own the file:

```bash
php artisan vendor:publish --tag=mcp-bridge-config
```

Every row says when the key is read:

- **Compile time.** Read when the manifest is compiled, so run `php artisan mcp-bridge:cache` after changing it, or clear the cached manifest.
- **Call time.** Read on every call or connection, so a change applies to the next one.
- **Boot.** Read when the application boots and registers the endpoints.
- **Command.** Read by the Artisan command that uses it, when it runs.

The server version sent to clients is the installed package version as Composer records it. There is no configuration key for it.

## Top level

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` (`MCP_BRIDGE_ENABLED`) | `true` | Master switch: `false` registers no server endpoint. Boot. |
| `mode` (`MCP_BRIDGE_MODE`) | `opt-in` | How routes are exposed: `opt-in`, `opt-out` or `read-only`. Compile time. |
| `tool_search_threshold` | `40` | Tool count per server past which clients get a search tool pair, or `0` for never. Call time. |
| `local` (`MCP_BRIDGE_LOCAL`) | `false` | Also serves every server over stdio through `mcp:start`. Boot. |

`mode` applies to routes only, and an unknown value behaves as `opt-in`. See [the security model](/guide/security#routes-follow-the-mode). Past the threshold, the flat tool list is replaced by `laravel/mcp`'s `search_tools` and `execute_tools`. Turn `local` on together with `security.local_actor`, because a subprocess carries no token. See [over stdio](/guide/connecting-clients#over-stdio).

## servers

Each entry becomes an MCP endpoint. See [servers and modules](/guide/servers-and-modules).

`groups` and `sources` decide which server a tool lands on, at compile time, in the order given in [where a tool lands](/guide/servers-and-modules#where-a-tool-lands). With the shipped values, route tools land on `default` and every other source's tools on `ops`. `prompts` entries that are not `laravel/mcp` `Prompt` classes are dropped.

| Key | Default | Effect |
| --- | --- | --- |
| `servers.<key>.path` | `/mcp` (`default`), `/mcp/ops` (`ops`) | The endpoint URL, and a server with none is skipped. Boot. |
| `servers.<key>.name` | `APP_NAME` plus " API" or " Operations" | The name sent to clients. Call time. |
| `servers.<key>.instructions` | `null` | Text sent to clients at `initialize`. `null` generates it from what the server serves (see [what the agent is told](/guide/ai-agents#what-the-agent-is-told)), a string replaces it, `false` sends none. Call time. |
| `servers.<key>.middleware` | `['auth:mcp-bridge', 'throttle:mcp']` (`default`); `['auth:mcp-bridge', 'throttle:mcp-ops']` (`ops`) | The endpoint's middleware, where authentication lives. Boot. |
| `servers.<key>.groups` | `['*']` (`default`), `['ops']` (`ops`) | Groups this endpoint claims: a tool carrying one, from `#[McpGroup]` or its documentation, is served here before its source's or module's server is consulted. `*` claims nothing. Compile time. |
| `servers.<key>.sources` | `['route', 'resource']` (`default`); `['command', 'job', 'action', 'model', 'remote', 'openapi']` (`ops`) | The sources this server serves, for a tool not placed by `sources.<key>.server` or a group (compile time). Also decides whether the server offers the four fixed resources (call time). The `records` template is the exception: it is offered by any server that carries a model `get` tool, whatever its `sources` says, when `sources.resource.expose` names `records` or `*`. With the shipped values that is `ops`. |
| `servers.<key>.prompts` | `[]` | `Prompt` classes served as they are. Call time. |
| `servers.<key>.tools` | `[]` | Your own `laravel/mcp` `Tool` classes, served as they are beside the generated tools. An entry that is not a `Tool` is dropped. Call time. |
| `servers.<key>.resources` | `[]` | Your own `laravel/mcp` `Resource` classes, served as they are. An entry that is not a `Resource` is dropped. Call time. |

## discovery

The structural filter for routes. See [routes](/sources/route#what-it-exposes).

Every allowlist here reads the same way: `*` is everything and an empty list is nothing, so a list never grants more than it says. `exclude`, `deny` and `except` lists are the other kind and take away from what was granted.

| Key | Default | Effect |
| --- | --- | --- |
| `discovery.prefixes` | `['api']` | URI prefixes considered; `*` is any. Also stripped from derived tool names. Compile time. |
| `discovery.include` | `['*']` | A route's URI, name or controller must match one of these `Str::is` patterns. Compile time. |
| `discovery.exclude` | `['api/*/webhooks/*', 'api/internal/*']` | A match on URI, name or controller removes the route before any attribute is read, so `#[McpTool]` cannot bring it back. Compile time. |
| `discovery.methods` | `['GET', 'POST', 'PUT', 'PATCH', 'DELETE']` | HTTP methods considered; `*` is any. Compile time. |
| `discovery.domains` | `['*']` | Hosts to discover from; `*` is every host. Compile time. |
| `discovery.middleware` | `[]` | Middleware a route must carry to be discovered, matched against the names the router reports: `auth:sanctum` is exact and `auth:*` is any guard. Every pattern listed has to match, which makes it the cheapest way to say nothing unauthenticated becomes a tool. Compile time. |
| `discovery.strip_version` | `true` | Drops `v1`-style segments from derived names. Compile time. |
| `discovery.cache` (`MCP_BRIDGE_CACHE`) | `true` | Reads `bootstrap/cache/mcp-bridge.php` when there is one. Without one, or with this off, the manifest is compiled on first use and kept for the life of the process, once per worker under Octane or stdio. Off is for local development. `mcp-bridge:doctor` treats a missing file as a problem in production while this is on. Call time. |
| `discovery.respect_docs_exclusions` | `true` | Keeps routes excluded from your Scramble or Scribe docs out of the tool list. Compile time. |

## sources

Each source has an `enabled` flag, read at compile time. See [the sources](/sources/). Every source also accepts `server`, `timeout` and `rate_limit`, which the compiler reads for every source, and only `command` and `job` ship with a `server`; the others are placed by the servers' `sources` lists, as [where a tool lands](/guide/servers-and-modules#where-a-tool-lands) describes.

`timeout` is the default for a tool without `#[McpTimeout]`, and it reaches neither the description nor the lint. It sets how long the tool's idempotency key is held while a call runs, and for an OpenAPI tool whose document block sets no `timeout` it is the HTTP client's timeout. It interrupts no in-process call. The tables below name the source in the heading, so a key is written without its `sources.<name>.` prefix.

### sources.route

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `true` | Turns the route source on. Compile time. |

### sources.command

`deny` adds patterns to the built-in floor in `CommandFilter::ALWAYS_DENY`, which emptying it cannot lift, and `allow_denied` is the only escape hatch, one command name at a time. `rate_limit` is per tool and per actor, in the format `N/second`, `N/minute`, `N/hour`, or a window such as `N/30seconds` or `N/5minutes`. A value in any other form refuses the tool with `MCP_BRIDGE_INVALID_RATE_LIMIT` rather than serving it unlimited, and an empty string is no limit.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `false` | Turns the command source on. Compile time. |
| `server` | `ops` | The server command tools go to, unless a server's `groups` claims the tool's `#[McpGroup]`. Compile time. |
| `namespaces` | `['*']` | Allowlist of command namespaces; `*` is any, `[]` is none. Compile time. |
| `deny` | `[]` | Extra deny patterns. Compile time. |
| `allow_denied` | `[]` | Command names let through the deny list. Compile time. |
| `destructive_by_default` | `true` | Commands start out destructive; `#[IsDestructive(false)]` or `#[IsReadOnly]` on the command says otherwise. Compile time. |
| `timeout` | `60` | Seconds a command tool without `#[McpTimeout]` holds its idempotency key while it runs. Compile time. |
| `rate_limit` | `'5/minute'` | Default per-tool, per-actor limit; `#[McpRateLimit]` on the command overrides it. Compile time. |
| `capture_output` | `true` | Returns what the command printed. Call time. |
| `output_tail_lines` | `20` | Lines of output kept in a failure message. Call time. |
| `stream_output` | `true` | Sends each printed line as a progress notification while the command runs, when the client sent a progress token. Call time. |

### sources.job

`reauthorize` asks the Gate again when a queued job runs, so revoking authority stops queued work. `paths` globs are expanded.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `false` | Turns the job source on. Compile time. |
| `server` | `ops` | The server job tools go to, unless a server's `groups` claims the tool's `#[McpGroup]`. Compile time. |
| `queue` | `mcp-bridge` | The queue agent-dispatched jobs go to; `#[McpQueue]` on the job overrides it. Call time. |
| `paths` | `['app/Jobs']` | Where job classes are scanned. Compile time. |
| `timeout` | `30` | Seconds a job tool without `#[McpTimeout]` holds its idempotency key while the call runs. Compile time. |
| `reauthorize` | `true` | Checks the Gate again when the job runs. Call time. |
| `status_ttl` | `24h` | How long `job_status` can answer for a queued job. Call time. |

### sources.action

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `false` | Turns the action source on. Compile time. |
| `paths` | `['app/Actions']` | Where action classes are scanned. Compile time. |

### sources.model

Turning `require_policy` off removes the per-record check.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `false` | Turns the model source on. Compile time. |
| `operations` | `['list', 'get']` | Which of `list`, `get`, `create`, `update`, `delete` become tools; `[]` is none. `#[McpTool(operations:)]` on a model narrows this list for that model and never widens it. Compile time. |
| `require_policy` | `true` | Requires a policy method per operation (compile time), asked per record (call time). |
| `paths` | `['app/Models']` | Where model classes are scanned. Compile time. |

### sources.resource

`scopes` is checked both when listing and when reading. `*` leaves the `app://` resources open to any authenticated caller, and an empty list grants nobody.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `true` | Serves app resources. Call time. |
| `expose` | `['routes', 'schema']` | Which of `routes`, `schema`, `schedule`, `config`, `records` are served; `*` is all five, `[]` is none. `records` is a template served on every server that carries a model `get` tool, whatever that server's `sources` says. Call time. |
| `scopes` | `['*']` | Scopes a token must hold to list or read the resources; `*` is any authenticated caller, `[]` is nobody. Call time. |

### sources.remote

See [remote servers](/sources/remote).

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `false` | Turns the remote source on. Compile time. |
| `servers` | `[]` | Remote servers, keyed by name. The tool lists are fetched at compile time; the URL and credential are read again on every call. |

### sources.openapi

See [OpenAPI documents](/sources/openapi). Each document block takes `spec`, `base_url`, `token`, `headers`, `timeout`, `scopes`, `abilities`, `only`, `except` and `prefix`, described there.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `false` | Turns the OpenAPI source on. Compile time. |
| `documents` | `[]` | OpenAPI documents, keyed by name. The document is read at compile time; `base_url`, `token`, `headers` and `timeout` are read again on every call. |

## modules

See [modules](/guide/servers-and-modules#modules).

`directories` is for the `directories` locator: each directory its globs match is a module named after itself. `grouping` is `module` or `none`: `module` labels a tool with its module as a group, and `none` adds none. Every key here is read at compile time.

| Key | Default | Effect |
| --- | --- | --- |
| `modules.enabled` | `true` | Detects modules. Compile time. |
| `modules.locators` | `['nwidart', 'path-repositories', 'directories']` | Which locators to ask; `*` is all, `[]` is none. Compile time. |
| `modules.directories` | `[]` | Globs of directories that are each a module. Compile time. |
| `modules.only` | `['*']` | Restricts to these modules; `*` is every module found, `[]` is none. Compile time. |
| `modules.except` | `[]` | Skips these modules. Compile time. |
| `modules.grouping` | `module` | `module` labels a tool with its module as a group; `none` adds no group. A `#[McpGroup]` on the tool replaces the module group. Compile time. |
| `modules.per_module_servers` | `false` | Gives each module its own server, named after the lower-cased module. Compile time. |
| `modules.always_prefix_module` | `false` | Prefixes every module tool's name with the snake-cased module name. Compile time. |

## execution

`forward_headers` is an allowlist, because the outer request is the MCP call and most of what it carries describes that call. Idempotency exists because an agent that never saw an answer retries, and a retry must not do the work twice; see [retries and idempotency keys](/concepts/output-and-errors#retries-and-idempotency-keys).

| Key | Default | Effect |
| --- | --- | --- |
| `execution.forward_headers` | `['Accept-Language', 'User-Agent']` | Headers copied from the outer request onto the sub-request a route tool makes. Call time. |
| `execution.middleware` | `[]` | Classes with `handle(ToolCall $call, Closure $next): ToolResult`, run in order after the gate has allowed a call and before the source runs it. A middleware that returns a result instead of calling `$next` stops the call, recorded as a refusal. A listed class that does not exist stops every call with `MCP_BRIDGE_SERVER_ERROR` naming the class, and `mcp-bridge:doctor` reports it. See [middleware around a call](/reference/php-api#middleware-around-a-call). Call time. |
| `execution.idempotency.enabled` | `true` | Offers every tool that is not read-only an `idempotency_key` input, bound to the tool, the actor and the arguments. Compile time. |
| `execution.idempotency.ttl` | `24h` | How long a key's successful answer is remembered. A failed call releases its key. Call time. |

## schema

| Key | Default | Effect |
| --- | --- | --- |
| `schema.rule_mappers` | `[]` | `MapsRules` classes asked before the built-in rule mapping. Compile time. |

A mapper lets an application say what its own validation rule means, instead of the rule becoming a note saying no schema can express it.

## naming

See [naming and grouping](/concepts/naming).

| Key | Default | Effect |
| --- | --- | --- |
| `naming.pattern` | `{verb}_{resource}` | Tool name order, where `{resource}_{verb}` flips it. Compile time. |
| `naming.style` | `snake` | Name casing: `snake`, `camel` or `kebab`. Compile time. |
| `naming.verbs` | `index: list`, `show: get`, `store: create`, `update: update`, `destroy: delete` | Maps resource actions to verbs. Compile time. |
| `naming.source_prefixes` | `['model' => 'model']` | A prefix for every derived name a source produces, keyed by source, joined in `naming.style`: `model_get_course`. A name from `#[McpTool(name: ...)]` is never prefixed, and an empty string turns a source's prefix off. See [source prefixes](/concepts/naming#source-prefixes). Compile time. |

## query

The query layer for list endpoints. See [list endpoints](/guide/list-endpoints).

`max_includes` stops an agent loading a whole relation tree. `max_include_depth` counts dots: `lessons.author` is two, `lessons.author.company` is three.

| Key | Default | Effect |
| --- | --- | --- |
| `query.enabled` | `true` | Reads allowed filters, sorts, includes and fields at all. Compile time. |
| `query.read_chains` | `true` | Reads fluent Spatie `QueryBuilder` chains by static analysis. Compile time. |
| `query.max_includes` | `3` | Includes allowed per call. Compile time. |
| `query.max_include_depth` | `2` | Deepest include allowed. Compile time. |

## protocol

Turning `cache_hints.enabled` off sends a zero lifetime. `scope` set to `public` is downgraded to `private` as soon as any tool on the server declares a scope or ability.

| Key | Default | Effect |
| --- | --- | --- |
| `protocol.cache_hints.enabled` | `true` | Tells clients how long they may reuse a list. Call time. |
| `protocol.cache_hints.ttl` | `5m` | How long a client may reuse a list. Call time. |
| `protocol.cache_hints.scope` | `private` | Whether a cached list may be shared. Call time. |

## output

How a successful result is shaped. See [output and errors](/concepts/output-and-errors). All **at call time**.

`max_items` also caps the page size offered to the agent, which is part of the schema and so read at compile time as well. `token_budget` drops rows from a list until it fits, at roughly four bytes a token, and one row always survives. With `structured` on, the text carries the summary line and then the same data as JSON, so turning it off costs a client parsing convenience rather than the result.

| Key | Default | Effect |
| --- | --- | --- |
| `output.max_items` | `25` | Most items returned from a list, and the largest `per_page` a list tool offers. `#[McpOutput(maxItems:)]` overrides both for one tool. |
| `output.max_string` | `2000` | Longer strings are truncated. |
| `output.token_budget` | `8000` | Row budget for a list, or `0` for none. |
| `output.drop_nulls` | `true` | Removes `null` values. |
| `output.structured` | `true` | Sends `structuredContent` as well as the text. |

## security

See [the security model](/guide/security). Each row says whether it is read at compile time or **at call time**.

Two strategies for `auth` exist because two kinds of thing connect: a person acting as themselves, and a server-to-server agent whose work is nobody's personal action. For OAuth, point the server's middleware at laravel/mcp's OAuth guard, since scopes map onto tools either way.

| Key | Default | Effect |
| --- | --- | --- |
| `security.destructive` | `confirm` | `confirm` asks first, `allow` runs unasked, `deny` never exposes. `#[McpConfirm]` forces confirmation on one tool whatever this says; under `deny`, only `#[IsDestructive(false)]` lets a tool through. Compile time. |
| `security.expose_error_details` | `false` | Appends the exception message to a server error. Leave it off outside local development. Call time. |
| `security.auth` | `passthrough` | `passthrough` runs each call as the token holder; `service-account` runs every call as `service_account`. Call time. |
| `security.service_account` | `null` | Id or email of the real user `service-account` runs as. When it does not resolve, the call is refused rather than falling back to the token holder. Call time. |
| `security.local_actor` (`MCP_BRIDGE_LOCAL_ACTOR`) | `null` | Id or email of the user a stdio call runs as. Read only for a server started with `mcp:start` and only when nothing authenticated the caller, so it never widens an HTTP surface; not read under `service-account`. With `local` on and this unset, every stdio call is refused. Call time. |
| `security.require_policy` | `false` | Refuses any write tool whose bound model has no policy, route tools included. Stricter than Laravel's own default, and worth it for an endpoint agents reach. Compile time. |
| `security.fill_from_auth` | `['user_id', 'created_by']` | Fields hidden from the agent because the application fills them, on every source. A create fills them with the actor's id, an update leaves them, and a remote or OpenAPI tool drops them. `#[McpParam(name, fromAuth: true)]` adds a field for one tool and `fromAuth: false` exempts one. Compile time. |
| `security.guards` | `['mcp-bridge', 'sanctum']` | Guards handed the user when a route tool runs. Call time. |
| `security.require_scope_for_non_http` | `true` | Non-route candidates must declare `#[McpScope]` and `#[McpAbility]`. Do not turn it off. Compile time. |
| `security.confirm_handle_ttl` | `5m` | How long a confirmation handle is good for. Call time. |
| `security.redact` | `['password', 'token', 'secret', 'national_id']` | Keys containing one of these, ignoring case, are removed from output and masked in the audit (call time), and a model column containing one is left out of the model source's filters, sorts and writes (compile time). See [redaction](/guide/audit-and-events#redaction). |

## integrations

See [documentation packages](/guide/documentation-packages).

| Key | Default | Effect |
| --- | --- | --- |
| `integrations.scramble.enabled` | `true` | Reads Scramble attributes as schema. Compile time. |
| `integrations.scramble.read_headers` | `false` | Turns documented headers into inputs. Compile time. |
| `integrations.scramble.responses` | `false` | Types each route tool's output from the response Scramble infers. Runs the generator once per compile. Compile time. |
| `integrations.scribe.enabled` | `true` | Reads Scribe docblock annotations as schema. Compile time. |

## tokens

Durations are written `30s`, `5m`, `2h` or `90d`. On the command line, `0` or `never` means no expiry.

| Key | Default | Effect |
| --- | --- | --- |
| `tokens.prefix` | `mcpb_` | Prepended to every token and checked by the guard before lookup. Call time. |
| `tokens.default_expiry` | `90d` | Lifetime of a new token when `--expires` is not given. Command (`mcp-bridge:token`). |

## panel

See [the token panel](/guide/panel).

| Key | Default | Effect |
| --- | --- | --- |
| `panel.enabled` | `true` | Serves the panel. `false` answers `404`. Read on each request, so it holds with a cached route table. |
| `panel.path` | `mcp-bridge` | Where the page lives. Boot. |
| `panel.middleware` | `['web', Authorize::class]` | The session stack and the check that opens the panel to the local environment or to users the `viewMcpBridge` gate allows. Add an `auth:<guard>` entry before `Authorize` to take the user from another guard. Boot. |

## uploads

See [file uploads](/guide/uploads).

| Key | Default | Effect |
| --- | --- | --- |
| `uploads.enabled` | `true` | Offers the `upload_file` tool where a tool takes a file. Call time. |
| `uploads.disk` | `local` | Disk staged files are written to. Call time. |
| `uploads.max_kb` | `10240` | Largest accepted file, in kilobytes. Call time. |
| `uploads.ttl` | `24h` | How long a staged file waits to be used. Call time, and by `mcp-bridge:prune-uploads`. |

## audit

See [audit and events](/guide/audit-and-events).

| Key | Default | Effect |
| --- | --- | --- |
| `audit.driver` | `database` | Where the trail goes: `database`, `log` or `none`. Call time. |
| `audit.retention_days` | `90` | What `mcp-bridge:prune-audit` keeps. Command (`mcp-bridge:prune-audit`). |

## database

| Key | Default | Effect |
| --- | --- | --- |
| `database.connection` | `null` | Connection for the package's tables, where `null` is the default. Read by the migrations and on every query. |
| `database.table_prefix` | `mcp_bridge_` | Prefix for `tokens`, `audit`, `uploads` and `kills`. Read by the migrations and on every query. |

## routes

Overrides keyed by route name. This is the last layer of the merge, and the only one that needs no change to the code it describes. Compile time.

| Key | Default | Effect |
| --- | --- | --- |
| `routes` | `[]` | `name` renames the tool, sanitised and module-prefixed like a name from the code, and `rate_limit` replaces its limit. Both beat the matching attribute, because this layer exists to change a tool without touching its code. |
