# Artisan commands

All commands are registered when the application runs in the console. Two groups matter most: the ones that build and inspect (`cache`, `tools`, `lint`, `call`, `doctor`), and the ones that operate (`token`, `kill`, `prune-*`).

| Command | Purpose |
| --- | --- |
| [`mcp-bridge:install`](#mcp-bridge-install) | Publish config, migrate, and in production compile a first manifest. |
| [`mcp-bridge:cache`](#mcp-bridge-cache) | Compile the tool manifest. |
| [`mcp-bridge:clear`](#mcp-bridge-clear) | Remove the compiled manifest. |
| [`mcp-bridge:tools`](#mcp-bridge-tools) | List the exposed tools, or what was refused. |
| [`mcp-bridge:lint`](#mcp-bridge-lint) | Check what would be exposed, with a CI exit code. |
| [`mcp-bridge:call`](#mcp-bridge-call) | Call a tool as an agent would. |
| [`mcp-bridge:export`](#mcp-bridge-export) | Write the compiled tools as JSON or Markdown. |
| [`mcp-bridge:describe`](#mcp-bridge-describe) | Show a tool's description as the agent reads it. |
| [`mcp-bridge:doctor`](#mcp-bridge-doctor) | Check configuration, guards, limiters, cache store, manifest, tables. |
| [`mcp-bridge:connect`](#mcp-bridge-connect) | Print ready-to-paste client setup. |
| [`mcp-bridge:token`](#mcp-bridge-token) | Issue a token. |
| [`mcp-bridge:tokens`](#mcp-bridge-tokens) | List and revoke tokens. |
| [`mcp-bridge:kill`](#mcp-bridge-kill) | Switch a tool, source, server or everything off. |
| [`mcp-bridge:prune-audit`](#mcp-bridge-prune-audit) | Delete old audit entries. |
| [`mcp-bridge:prune-tokens`](#mcp-bridge-prune-tokens) | Delete tokens that stopped working. |
| [`mcp-bridge:prune-uploads`](#mcp-bridge-prune-uploads) | Delete staged files never used. |

## mcp-bridge:install

```bash
php artisan mcp-bridge:install [--force]
```

The first five minutes in one command: publishes the configuration (`--force` overwrites an existing one), runs `migrate`, compiles a manifest when the environment is `production` (anywhere else the manifest compiles on demand, and a file written now would be stale after the next attribute), and prints the three things left for you: add `HasMcpTokens` to the user model, put `#[McpTool]` on actions, issue a token and connect.

## mcp-bridge:cache

```bash
php artisan mcp-bridge:cache
```

Compiles the manifest from every enabled source and writes it to `bootstrap/cache/mcp-bridge.php`. Reports how many tools were compiled and how many candidates were not exposed. A tool name collision does not fail it: the later candidate is refused with `MCP_BRIDGE_NAME_TAKEN` and `mcp-bridge:tools --refused` shows it (see [collisions](/concepts/naming#collisions)). Run it on every deploy. `php artisan optimize` runs it for you, after `config:cache` and `route:cache`.

## mcp-bridge:clear

```bash
php artisan mcp-bridge:clear
```

Removes `bootstrap/cache/mcp-bridge.php`. `php artisan optimize:clear` runs it for you. Servers keep listing tools: without the file, the manifest is compiled from the application on first use and kept for the life of the process. A long-lived process (Octane, a stdio server) keeps what it compiled or read until it restarts.

## mcp-bridge:tools

```bash
php artisan mcp-bridge:tools [tool] [--source=] [--module=] [--server=] [--refused] [--explain]
```

Lists the compiled tools: name, source, module, server, target and safety (`destructive`, `read-only` or a write). Reads the manifest the way a server does: the cached file when there is one and `discovery.cache` is on, and otherwise a manifest compiled on the spot. The three filters narrow the list. When nothing is exposed at all, it says so and points at `--refused`.

`--refused` compiles fresh and instead lists every candidate that was found and not exposed, as candidate id, code and reason. It answers most "why is my tool missing" questions, including a name collision (`MCP_BRIDGE_NAME_TAKEN`), whose message names the candidate holding the name and the fix.

`<tool> --explain` answers the other recurring question, which is why a value is what it is. A tool's inputs are merged from the six [schema layers](/concepts/schema#the-layers), and its other values from attributes, documentation, configuration and source defaults. This says which one won: how its name, description, safety and rate limit were decided, what authority it carries, and which layers set each input. It reads what the compiler wrote down rather than working it out again, so it cannot disagree with the manifest a client is served.

```bash
php artisan mcp-bridge:tools list_courses --explain
```

## mcp-bridge:lint

```bash
php artisan mcp-bridge:lint [--source=] [--all] [--json]
```

Checks what the application would expose, before an agent does. Reads the sources rather than the manifest, applying the same checks the compiler does. Findings have a level (`error`, `warning`, `note`), a code, a subject and a hint. `--source` limits the check to one source, `--all` includes deliberate choices (not opted in, ignored), and `--json` prints machine-readable output for CI.

Exits non-zero when any finding is an `error`. Codes and meanings are in the [error codes reference](/reference/error-codes#lint-findings).

## mcp-bridge:call

```bash
php artisan mcp-bridge:call <tool> [--arg=*] [--json=] [--as=] [--scopes=] [--confirm=]
```

Calls a tool the way an agent would and prints the actor it runs as beside the scopes it holds (for an actor with `HasMcpTokens`), then what the tool returns: the text, then the structured content as JSON. It goes through `ToolRunner`, so the gate, execution, shaping and audit all apply. It is a real call: it runs the real tool and writes a real audit entry. A tool name that is not in the manifest fails with a pointer to `mcp-bridge:tools --refused`.

| Option | Meaning |
| --- | --- |
| `tool` | The tool name, looked up in the manifest the server reads (the cached file when there is one, otherwise compiled on the spot). |
| `--arg=name=value` | One argument. Repeat for several. A dotted name builds nesting (`filter.status=published`). Values `true`, `false`, `null` and numbers are cast; a value containing a comma becomes a list. |
| `--json='{...}'` | All arguments as a JSON object. Takes precedence over `--arg`. |
| `--as=` | The user to call as, by id or email. Defaults to the user with id `1`; name a user when there is none. |
| `--scopes=` | Comma-separated scopes the call holds. Omitted, it is `*`, every scope. An empty value (`--scopes=`) holds no scope at all. |
| `--confirm=` | A confirmation handle from a previous call. |

A user loaded from the database holds no token, and the scope check fails closed, so every tool that declares a scope would refuse a shell call. `--scopes` attaches an unsaved package token holding those scopes to the actor, the way the guard attaches a real one, so the gate sees what it would see on a connection. Narrow it to try a tool as a scoped token would: `--scopes=courses:read`. The actor's model needs `HasMcpTokens` to hold the token; without it the command warns, and a tool that declares a scope refuses the call. Abilities are not affected: the Gate still answers them for the actor.

Exits 1 when the call is an error. A destructive tool answers the first call with a preview and a handle (not an error), and you call again with `--confirm`.

```bash
php artisan mcp-bridge:call list_courses --arg=per_page=2 --arg=filter.status=published
php artisan mcp-bridge:call publish_course --arg=course=12 --scopes=courses:write --as=ops@example.com
php artisan mcp-bridge:call delete_course --arg=course=7
php artisan mcp-bridge:call delete_course --arg=course=7 --confirm=<handle>
```

## mcp-bridge:export

```bash
php artisan mcp-bridge:export [--format=json] [--path=]
```

Compiles fresh and writes every tool as JSON (the default) or Markdown (`--format=markdown`) to standard output, or to `--path`. The Markdown lists each tool's description, source, target, safety, scopes and visible inputs. Read it the way an agent would.

## mcp-bridge:describe

```bash
php artisan mcp-bridge:describe [tool] [--ai]
```

Prints each tool's description exactly as an agent receives it, with the safety note, required scopes, examples and deprecation notice the compiler appended, or one tool's when named. Reads the manifest the way a server does: the cached file when there is one, otherwise a manifest compiled on the spot. Exits non-zero when the named tool is not in the manifest. When nothing is exposed at all, it says so and points at `mcp-bridge:tools --refused`.

`--ai` rewrites descriptions with an LLM and needs the companion package `kayedspace/laravel-mcp-bridge-ai`; without it the command says so and exits non-zero. It is a separate package so that no LLM SDK sits in the dependency tree of an application running this one in production.

## mcp-bridge:doctor

```bash
php artisan mcp-bridge:doctor
```

Runs these checks and prints `ok` or `look at this` for each:

- the package is enabled;
- each server has a `path` and a registered route, and no other `laravel/mcp` server has taken that path;
- each `auth:` guard named in a server's middleware is defined (and tells you to "define it in config/auth.php");
- each `throttle:` limiter named is defined (and tells you to "define it in a service provider");
- when `local` is on, who stdio calls run as: the service account, or a `security.local_actor` that resolves to a user;
- the cache store: `cache.default` must not resolve to the `array` or `null` driver, because confirmation handles, idempotency keys and job statuses live in the cache and need a store every worker shares;
- "Call middleware": each class in `execution.middleware` exists and has a `handle()` method, because a missing one stops every call;
- the compiled manifest: fine when the file exists or `discovery.cache` is off. Outside production a missing file is fine too ("compiled on demand; run mcp-bridge:cache in your deploy"); in production it fails until the file is written;
- the `tokens`, `audit` and `kills` tables exist ("run migrate");
- what the kill switch has turned off.

Exits non-zero when anything needs attention.

With [`laravel/doctor`](https://github.com/laravel/doctor) installed, the same checks are registered as diagnostics in the `mcp-bridge` group under the package name, so `php artisan doctor` reports them beside the framework's and `--only=kayedspace/laravel-mcp-bridge` narrows a run to them. The two commands read one `Checkup`, so they cannot disagree. A switched-off tool is a notice there rather than a failure, and a disabled package a warning, because both are states someone chose.

## mcp-bridge:connect

```bash
php artisan mcp-bridge:connect [client=claude] [--server=default] [--token=] [--stdio] [--apply] [--skill]
```

Prints ready-to-paste setup for one client. The URL is `app.url` plus the chosen server's `path`, and `--token` replaces the placeholder `mcpb_your_token_here`. An unknown `client` fails and lists the known ones.

| `client` | What it prints | What `--apply` does |
| --- | --- | --- |
| `claude` | A `claude mcp add --transport http` command | Runs it |
| `codex` | An `export <APP>_MCP_TOKEN=...` line and a `codex mcp add --url ... --bearer-token-env-var` command, so the token stays out of Codex's configuration | Runs the command, then reminds you to export the variable in your shell profile |
| `gemini`, `qwen` | A `gemini mcp add` or `qwen mcp add --transport http` command with an `Authorization` header | Runs it |
| `copilot` | A `copilot mcp add --transport http` command with an `Authorization` header | Runs it |
| `cursor` | An `mcpServers` block for `.cursor/mcp.json` | Merges it into that file |
| `vscode` | A `servers` block for `.vscode/mcp.json` | Merges it into that file |
| `opencode` | An `mcp` block with `"type": "remote"` for `opencode.json` | Merges it into that file |
| `windsurf` | An `mcpServers` block with `serverUrl` for `~/.codeium/windsurf/mcp_config.json` | Prints the block, since the file is outside the project |
| `chatgpt`, `n8n` | The block for the connector settings or the MCP Client node | Prints the block, since there is no local file |
| `json` | A plain `mcpServers` block for any other client, such as Zed, Kiro or Cline | Prints the block |

A command-configured client is run through its binary on the PATH; when the binary is missing, `--apply` fails and prints the command to run where it is. A merged file keeps every other server already listed in it, and then holds the token, so keep it out of version control. `--token` is required with `--apply` over HTTP.

`--skill` also writes the server's instructions, the text a client receives at `initialize`, as a Claude Code skill at `.claude/skills/<app>-mcp/SKILL.md` under the application's base path, for a team that keeps skills in the repository. See [what the agent is told](/guide/ai-agents#what-the-agent-is-told).

`--stdio` prints the setup for running the application as a subprocess instead, and `--apply` applies that the same way: each client's own stdio form running `php artisan mcp:start <server>`, as an `mcp add` command or a block with `command` and `args`. It warns when `local` is off or `security.local_actor` names nobody, because a subprocess carries no token. See [over stdio](/guide/connecting-clients#over-stdio).

## mcp-bridge:token

```bash
php artisan mcp-bridge:token <user> [--name=] [--scopes=] [--client=] [--expires=]
```

Issues a package token for a user by id or email. The user model needs `HasMcpTokens`. The plain token is printed once. `--scopes` omitted records `*`, every scope; an empty value (`--scopes=`) records an empty list, which holds no scope. See [connecting a client](/guide/connecting-clients#tokens) for the options.

## mcp-bridge:tokens

```bash
php artisan mcp-bridge:tokens [--revoke=] [--all]
```

Lists active tokens with id, name, owner, scopes, client, last use and state. `--all` includes revoked and expired ones. `--revoke=<id>` revokes one, effective on the agent's next request; the id is the integer the list shows.

## mcp-bridge:kill

```bash
php artisan mcp-bridge:kill [tool] [--source=] [--server=] [--all] [--revive] [--list]
```

Switches off a tool (argument), every tool from a source (`--source=command`), every tool on a server (`--server=ops`) or the whole surface (`--all`). `--revive` turns back on what a given target switched off, and `--all --revive` clears everything. `--list` shows what is off. With no target it exits 1. Stored in the `mcp_bridge_kills` table with no expiry, so `cache:clear` does not revive it. Workers read the table through the cache ten seconds at a time; the command clears that cached copy, so with a shared cache store the next listing or call anywhere sees the change. See [the kill switch](/guide/operations#the-kill-switch).

## mcp-bridge:prune-audit

```bash
php artisan mcp-bridge:prune-audit [--older-than=]
```

Deletes audit entries older than the window. `--older-than` takes whole days, as `90d` or `90`, and defaults to `audit.retention_days`. Any other spelling falls back to the configured retention.

## mcp-bridge:prune-tokens

```bash
php artisan mcp-bridge:prune-tokens [--days=30]
```

Deletes tokens that were revoked or expired more than `--days` ago. They are kept for a while after they stop working, because an audit entry that names a token nobody can look up explains less.

## mcp-bridge:prune-uploads

```bash
php artisan mcp-bridge:prune-uploads [--older-than=]
```

Deletes staged files, and their rows, that were created longer ago than the window, whether or not a tool claimed them. `--older-than` takes `30s`, `5m`, `2h` or `1d` style durations and defaults to `uploads.ttl`.
