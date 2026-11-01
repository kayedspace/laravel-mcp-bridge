# Servers and modules

## Servers

A server is one MCP endpoint with its own path, name, middleware and tool surface. Each entry under `servers` in `config/mcp-bridge.php` is registered as a `laravel/mcp` web endpoint backed by `GeneratedServer`. Two ship by default.

```php
'servers' => [
    'default' => [
        'path' => '/mcp',
        'name' => env('APP_NAME', 'Laravel').' API',
        'instructions' => null,
        'middleware' => ['auth:mcp-bridge', 'throttle:mcp'],
        'groups' => ['*'],
        'sources' => ['route', 'resource'],
        'prompts' => [],
    ],

    'ops' => [
        'path' => '/mcp/ops',
        'name' => env('APP_NAME', 'Laravel').' Operations',
        'instructions' => null,
        'middleware' => ['auth:mcp-bridge', 'throttle:mcp-ops'],
        'groups' => ['ops'],
        'sources' => ['command', 'job', 'action', 'model', 'remote', 'openapi'],
    ],
],
```

The point of two servers is that an operations token and an API token reach different surfaces, not the same one with different filtering. A token that can call `list_courses` cannot even see `recalculate_stats`.

| Key | Effect |
| --- | --- |
| `path` | The URL of the endpoint. A server with no path is skipped, and `mcp-bridge:doctor` reports it. |
| `name` | The server name sent to clients. Falls back to `APP_NAME`. |
| `instructions` | Text sent to clients at `initialize`. `null` generates it from what the server serves, a string replaces it, `false` sends none. See [what the agent is told](/guide/ai-agents#what-the-agent-is-told). |
| `middleware` | The middleware on the endpoint. This is where authentication lives. |
| `sources` | Which sources this server serves. Places a tool whose source has no `sources.<key>.server`, and decides whether the server offers the fixed resources. The `records` template is the exception, described below. |
| `prompts` | `laravel/mcp` `Prompt` classes served as they are. |
| `tools` | Your own `laravel/mcp` `Tool` classes, served as they are beside the generated tools. |
| `resources` | Your own `laravel/mcp` `Resource` classes, served as they are. |
| `groups` | A tool carrying one of these groups is served from this endpoint whatever source it came from. `*` claims nothing: it means whatever arrives here by source. |

`mcp-bridge:connect --server=ops` prints the setup for a specific server.

### Where a tool lands

Every tool is placed on exactly one server when the manifest compiles. `ManifestCompiler` asks these in order, and the first that answers wins:

1. **A server whose `groups` claims one of the tool's groups.** A tool's groups come from `#[McpGroup]` or the documentation's group (Scramble `#[Group]`, Scribe `@group`), and for a remote or OpenAPI tool from its server or document key. `*` claims nothing. The code names a concept and the configuration maps it to an endpoint, so one route or one command moves without moving the rest.
2. **The module's server**, when `modules.per_module_servers` is on and the tool lives in a module: the server named after the lower-cased module (`ModuleRegistry::server()`).
3. **`sources.<key>.server`**, when set. The shipped configuration sets it for `command` and `job`, both to `ops`.
4. **The first server whose `sources` lists the tool's source.**
5. **`default`.**

With the shipped configuration that gives:

| Source | Server | Middleware | Decided by |
| --- | --- | --- | --- |
| `route` | `default` (`/mcp`) | `auth:mcp-bridge`, `throttle:mcp` | `default` lists `route` |
| `command`, `job` | `ops` (`/mcp/ops`) | `auth:mcp-bridge`, `throttle:mcp-ops` | `sources.<key>.server` |
| `action`, `model`, `remote`, `openapi` | `ops` (`/mcp/ops`) | `auth:mcp-bridge`, `throttle:mcp-ops` | `ops` lists the source |

A source listed on no server lands on `default`, which is the application's own API surface. That is why `ops` names every non-route source: a model tool that can delete records should not arrive on `default` because nobody wrote it down.

Groups move single tools. `#[McpGroup('ops')]` on one controller action serves that route from `/mcp/ops` and leaves the rest of the controller on `/mcp`.

Resources are not tools and are not placed by these rules:

- **The fixed resources** (`routes`, `schema`, `schedule`, `config`) are offered by a server whose `sources` lists `resource`.
- **The `records` template** is offered by any server that carries a model `get` tool, whatever its `sources` says, when `sources.resource.expose` names `records` or `*`. With the shipped configuration that server is `ops`.

Over HTTP a request is mapped back to its server by path, so each endpoint serves only its own tools.

### Prompts

A prompt is written, not found: it is for a job an agent does repeatedly, and nothing in an application already is one. List classes extending `Laravel\Mcp\Server\Prompt`:

```php
'servers' => [
    'default' => [
        // ...
        'prompts' => [App\Mcp\Prompts\DraftCourseOutline::class],
    ],
],
```

An entry that is not a prompt class, or no longer exists after a rename, is dropped rather than taken on trust, because a configuration typo should not be able to take the whole surface down. Prompt names are chosen by `laravel/mcp`, not by this package.

### Tool search

Past a certain number of tools on one server, listing every one stops helping: the client spends its context on a catalogue instead of the work, and an agent picks worse from forty descriptions than from the five it searched for. When a server holds more than `tool_search_threshold` tools (default `40`), the flat list is replaced by `laravel/mcp`'s search and execute pair, `search_tools` and `execute_tools`, so the catalogue is queried rather than recited. `0` turns it off. The [built-in tools](/sources/#built-in-tools) count towards the threshold.

### Cache hints

The 2026-07-28 revision lets a server say how long a client may reuse a list it already has. `protocol.cache_hints` sets that for `server/discover`, `tools/list`, `prompts/list`, `resources/list` and `resources/templates/list`:

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `true` | Send hints. Off sends a zero lifetime, which this revision spells "do not keep this". |
| `ttl` | `5m` | How long a client may reuse a list. |
| `scope` | `private` | `tools/list` is filtered per caller, so it is never shared. `public` is downgraded to `private` as soon as any tool on the server declares a scope or ability. |


## Next to an existing laravel/mcp setup

An application that already serves its own `laravel/mcp` servers keeps them. Whatever `routes/ai.php` registers goes on serving as before, and this package adds its servers beside them. There are two ways to arrange the two.

**Separate servers.** Leave your servers where they are, and give the package's servers paths of their own. An agent then connects to each one it needs.

**One server.** List your own classes on a package server, and they are served as they are, beside the generated tools, behind the same middleware and token:

```php
'servers' => [
    'default' => [
        'path' => '/mcp',
        'tools' => [App\Mcp\Tools\CurrentWeather::class],
        'resources' => [App\Mcp\Resources\StaffHandbook::class],
        'prompts' => [App\Mcp\Prompts\DraftCourseOutline::class],
    ],
],
```

Your classes keep their own logic: the package does not wrap them, so the scope, ability and confirmation checks it gives generated tools do not apply to them. Authorize inside them, as you already do.

Two servers cannot share a path. When your own server is registered at a path a package server also uses, one of them replaces the other without an error. `php artisan mcp-bridge:doctor`, and `php artisan doctor`, report it and name the server holding the path; move one of them.

## Modules

In a modular application the question "which part of the system is this tool from" matters, and a name like `list_invoices` can collide across modules.

Ownership is decided by **file path**, not by namespace or naming convention: a class belongs to the module whose directory contains the file it was declared in. That holds whatever the module package is called and needs no configuration to get right.

Nothing about modules is needed to discover tools. Routes come from the router and commands from the console registry whatever module declared them, and the path-scanned sources take globs. A locator answers one question: what this class's module is called, for grouping, name prefixes and per-module servers. Three ship:

| Locator | Finds |
| --- | --- |
| `nwidart` | Modules as `nwidart/laravel-modules` knows them. A module disabled there stays invisible here. |
| `path-repositories` | Composer `path` repositories in your `composer.json`, such as `packages/*` or `modules/*`. |
| `directories` | Every directory matched by a glob in `modules.directories`, each a module named after itself. For modules that are a folder convention and a service provider rather than a package. |

```php
'modules' => [
    'enabled' => true,
    'locators' => ['nwidart', 'path-repositories', 'directories'],
    'directories' => ['app/Modules/*'],
    'only' => ['*'],
    'except' => [],
    'grouping' => 'module',
    'per_module_servers' => false,
    'always_prefix_module' => false,
],
```

| Key | Effect |
| --- | --- |
| `enabled` | Detect modules at all. With none found, the package says nothing about modules. |
| `locators` | Which locators to ask; `*` is all of them, an empty list none. |
| `directories` | Globs for the `directories` locator, relative to the application root unless absolute. `['app/Modules/*']` makes `app/Modules/Billing` the module `Billing`. Empty by default. |
| `only`, `except` | Restrict to or skip named modules. `only` is an allowlist, `*` for every module found and `[]` for none; `except` removes from that. |
| `grouping` | `module` or `none`. `module` labels each tool that declares no group of its own with its module; `none` adds no group. |
| `per_module_servers` | Give each module's tools a server named after the lower-cased module. Define a server with that key to serve them. |
| `always_prefix_module` | Prefix every module tool's name with the snake-cased module name, to avoid collisions. Off by default, because a prefix on every tool is noise until needed. The collision is reported either way. |

A module layout none of the three locators reads needs a locator of your own. See [adding a module locator](/reference/php-api#adding-a-module-locator).

Jobs, actions and models are found by scanning directories, so a modular application names every module's directory at once with a glob:

```php
// config/mcp-bridge.php
'sources' => [
    'job' => ['paths' => ['app/Jobs', 'Modules/*/Jobs']],
    'action' => ['paths' => ['app/Actions', 'Modules/*/Actions']],
    'model' => ['paths' => ['app/Models', 'Modules/*/Models']],
],
```

`mcp-bridge:tools --module=Billing` lists one module's tools.
