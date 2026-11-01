# Sources

A [source](/concepts/how-it-works#glossary) finds things your application already declares and turns them into tool candidates. There are eight. Each is switched on under `sources.<key>` in `config/mcp-bridge.php`.

| Source | Finds | Enabled by default | Opt-in | Authority | Default server |
| --- | --- | --- | --- | --- | --- |
| [`route`](/sources/route) | HTTP routes bound to controller actions | Yes | By `mode` | Inherited from the route | `default` |
| [`command`](/sources/command) | Artisan commands | No | `#[McpTool]`, every mode | `#[McpScope]` and `#[McpAbility]` | `ops` |
| [`job`](/sources/job) | Queueable job classes | No | `#[McpTool]`, every mode | `#[McpScope]` and `#[McpAbility]` | `ops` |
| [`action`](/sources/action) | Action classes | No | `#[McpTool]`, every mode | `#[McpScope]` and `#[McpAbility]` | `ops` |
| [`model`](/sources/model) | Eloquent models, per operation | No | `#[McpTool]`, every mode, plus a policy | `#[McpScope]` and `#[McpAbility]` | `ops` |
| [`resource`](/sources/resource) | Read-only facts about the application | Yes | `sources.resource.expose` | `sources.resource.scopes`, behind the server's middleware | Servers listing `resource` (`default`); the `records` template on servers with a model `get` tool (`ops`) |
| [`remote`](/sources/remote) | Tools from another MCP server | No | Configuration | `scopes` and `abilities` in config | `ops` |
| [`openapi`](/sources/openapi) | Operations of an OpenAPI document, for an API this application calls | No | Configuration | `scopes` and `abilities` in config | `ops` |

Only HTTP routes follow the `mode` setting. Every other source is opt-in per item in every mode, because it has no middleware or policy to inherit. The reasoning is on the [security page](/guide/security).

The table is in compile order, and third-party sources follow it by key. When two candidates want one tool name, the earlier source keeps it and the later candidate is refused, never the build: see [collisions](/concepts/naming#collisions). Model tools carry a `model_` prefix (`model_get_course`) so that they do not want the names of the routes in front of the same model: see [source prefixes](/concepts/naming#source-prefixes).

## Which server a tool lands on

Route tools land on `default` (`/mcp`, behind `auth:mcp-bridge`) and every other source's tools on `ops` (`/mcp/ops`, behind `auth:mcp-bridge`). The full resolution order, including groups and per-module servers, is in [where a tool lands](/guide/servers-and-modules#where-a-tool-lands).

## Built-in tools

A few tools come from no source. The server adds them itself when they are needed, outside the compiled manifest.

| Tool | Appears on | What it does |
| --- | --- | --- |
| `upload_file` | A server with at least one tool that takes a file, while `uploads.enabled` is on | Stages a file sent as base64 and returns an id to pass as that tool's file input. See [file uploads](/guide/uploads). |
| `job_status` | A server with at least one job tool | Answers what became of a job a tool queued: `queued`, `running`, `done` or `failed`. See [what became of it](/sources/job#what-became-of-it). |
| `search_tools`, `execute_tools` | A server holding more than `tool_search_threshold` tools (default `40`) | `laravel/mcp`'s `ToolSearch` pair. They replace the flat list: the agent searches the catalogue, then runs what it found. See [tool search](/guide/servers-and-modules#tool-search). |

## Which attributes apply where

Most [attributes](/reference/attributes) work on every source, because authority, safety, limits, output shaping and deprecation are read from the declaration by the compiler. A few are source-specific.

| Attribute | Routes | Commands | Jobs | Actions | Models |
| --- | --- | --- | --- | --- | --- |
| `#[McpTool]` name, title, description | Yes | Yes | Yes | Yes | Opt-in only; the name and description are generated |
| `#[McpScope]`, `#[McpAbility]` | Optional | Required | Required | Required | Required |
| `#[McpConfirm]`, `#[McpRateLimit]`, `#[McpTimeout]`, `#[McpDeprecated]`, `#[McpExample]`, `#[McpOutput]`, `#[McpGroup]`, `#[McpIgnore]` | Yes | Yes | Yes | Yes | Yes |
| `#[McpParam]` | Yes | No | No | No | No |
| `#[McpInput]` | Yes | No | No | No | Yes, for create and update |
| `#[McpQueue]` | No | No | Yes | No | No |
| Query attributes (`#[QueryModel]`, `#[AllowFilter]`, ...) and `#[Paginate]` | Yes | No | No | No | No |

Remote and OpenAPI tools carry no attributes: everything they declare is in their configuration block. An attribute that nothing reads on a given source is ignored silently, so check this table when an attribute seems to do nothing.

## Adding your own

The source contract is the package's main extension point. A new source implements `KayedSpace\McpBridge\Contracts\Source` (`key()`, `discover()`, `schema()`, `validate()`, `execute()`) and is registered on the `SourceRegistry`. It never decides whether a call is allowed, never writes audit entries and never shapes its own output. See the [PHP API reference](/reference/php-api#sources).
