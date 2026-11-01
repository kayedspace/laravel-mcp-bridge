# Connecting a client

A client reaches the package over HTTP at the `path` of a configured [server](/guide/servers-and-modules), `/mcp` by default. What it needs is a URL and a bearer token.

## Tokens

The package ships its own token type, the package token, so an agent's credential can be narrower than a user's session and can be revoked without touching the user. The user model needs the `HasMcpTokens` trait to hold one.

```bash
php artisan mcp-bridge:token you@example.com \
    --name=Claude \
    --client=claude-code \
    --scopes=courses:read,courses:write \
    --expires=30d
```

| Option | Meaning |
| --- | --- |
| `user` (argument) | The user the token acts as, by id or email. |
| `--name` | What the token is for, shown when listing. Defaults to `Agent token`. |
| `--scopes` | Comma-separated scopes. Omit for every scope, recorded as `*`, which the command warns about. An empty value (`--scopes=`) holds no scope. |
| `--client` | Which agent this is for, recorded in the audit trail. |
| `--expires` | `30s`, `5m`, `2h`, `90d`, or `never`. Defaults to `tokens.default_expiry` (`90d`). |

The plain token is printed once. Only a SHA-256 hash is stored, so a leaked database row is not a credential. Tokens start with `tokens.prefix` (`mcpb_`), which lets the guard ignore a token that belongs to another guard in the stack.

### Four ways to issue one

| Who issues it | How | Scopes |
| --- | --- | --- |
| An operator, for anyone | `mcp-bridge:token`, above. | `--scopes` |
| A signed-in user, for themselves | The [token panel](/guide/panel) the package serves, open to the local environment and to users the `viewMcpBridge` gate allows. | A checkbox per scope the manifest declares, plus `*` and a field for others. |
| A signed-in Filament user, for themselves | The [Filament page](/guide/filament), which lists their tokens, issues one with a name, scopes, client and expiry, and revokes one. | A tag per scope in the form. |
| Your own endpoint or screen | `$user->createMcpToken($name, $scopes)` returns a `NewMcpToken`: `->plainText` is the token to hand over, and `->accessToken` is the stored record. `McpToken::issue($model, ...)` does the same for any model carrying the trait. | Whatever your code passes; `['*']` is every scope, `[]` is none, and there is no default. |

The panel and the Filament page are screens for a person. The package ships no JSON API for issuing tokens on purpose: which programs may issue one, under which guard and with which scopes is the application's decision, and a controller for it is a few lines:

```php
use Illuminate\Http\Request;

public function store(Request $request)
{
    $data = $request->validate([
        'name' => ['required', 'string', 'max:100'],
        'scopes' => ['required', 'array'],
        'scopes.*' => ['string', 'in:courses:read,courses:write'],
    ]);

    $token = $request->user()->createMcpToken($data['name'], $data['scopes']);

    return response()->json([
        'token' => $token->plainText,
        'expires_at' => $token->accessToken->expires_at,
    ], 201);
}
```

Validate the scopes against the list your tools declare, as above, so a user cannot grant themselves a scope the screen never offered. The plain text is in that one response and nowhere else.

List and revoke:

```bash
php artisan mcp-bridge:tokens            # active tokens
php artisan mcp-bridge:tokens --all      # including revoked and expired
php artisan mcp-bridge:tokens --revoke=3
```

Revocation takes effect on the next request. Delete old rows with `mcp-bridge:prune-tokens`, which keeps tokens that stopped working in the last `--days` (default 30) so the audit trail still has something to name.

### What scopes mean

A scope is a string your tools ask for with [`#[McpScope]`](/reference/attributes#mcpscope). A token holds every scope only when it carries `*`, which is what `mcp-bridge:token` records when `--scopes` is omitted and what `createMcpToken()` records when passed `['*']`; a token issued with an empty list holds nothing. Otherwise a granted scope matches a required one when they are equal or when the granted value is a `Str::is` pattern that matches, so `courses:*` covers `courses:read`. Issue the narrowest set the agent needs.

Scopes are checked when the tool list is built and again when a tool is called. See the [security model](/guide/security).

## The guard

`auth:mcp-bridge` is the package's guard. It reads the bearer token, checks it is usable (not revoked, not expired), loads the user and puts the token on that user. Scopes are not decided here, because the answer depends on which tool is called. The guard only makes the token available to the authorisation layer.

Point a server at it in `config/mcp-bridge.php`:

```php
'servers' => [
    'default' => [
        'path' => '/mcp',
        'middleware' => ['auth:mcp-bridge', 'throttle:mcp'],
        // ...
    ],
],
```

The shipped `ops` server already uses it. The package registers the guard entry in `auth.guards` when your application has not, using your default user provider.

### With Sanctum, Passport or another guard

The scope check asks the package's own token first, through `currentMcpToken()`, when the user arrived with one. Otherwise it asks `tokenCan($scope)`, which Sanctum and Passport users answer. A user with neither is refused for every tool that declares a scope: the check [fails closed](/guide/security#a-guard-that-cannot-report-abilities-fails-closed).

`HasMcpTokens` and Sanctum's `HasApiTokens` sit on one model without conflict, because nothing in this package's trait is called `tokenCan()`.

Keep `security.guards` listing the guards your route middleware uses (`mcp-bridge` and `sanctum` by default). When a route tool runs, the package hands the already-authenticated user to each of those guards so the route's own `auth:` middleware passes without authenticating a second time. The route's other middleware still runs and still decides.

## Clients

`mcp-bridge:connect` prints the setup for each client, or applies it on this machine with `--apply`:

```bash
php artisan mcp-bridge:connect claude   --token=mcpb_...
php artisan mcp-bridge:connect codex    --token=mcpb_...
php artisan mcp-bridge:connect gemini   --token=mcpb_...
php artisan mcp-bridge:connect copilot  --token=mcpb_...
php artisan mcp-bridge:connect cursor   --token=mcpb_...
php artisan mcp-bridge:connect vscode   --token=mcpb_...
php artisan mcp-bridge:connect opencode --token=mcpb_...
php artisan mcp-bridge:connect claude --token=mcpb_... --apply   # runs claude mcp add here
```

| Client | Where it is configured |
| --- | --- |
| Claude Code, Codex, Gemini CLI, Qwen Code, GitHub Copilot CLI | Their own `mcp add` command, which `--apply` runs. Codex reads the token from an environment variable, so the token never lands in its configuration |
| Cursor, VS Code, opencode | A file in the project, which `--apply` merges into: `.cursor/mcp.json`, `.vscode/mcp.json` or `opencode.json` |
| Windsurf, ChatGPT, n8n | Settings outside the project, so the block is printed to paste |
| Any other client | `mcp-bridge:connect json` prints the common `mcpServers` block that Zed, Kiro, Cline and most others accept |

Printing is the default because the client's configuration lives on the client's machine, which in production is not the server's. On a developer's laptop the two are one, and `--apply` does the connecting. The URL is built from `app.url` and the server's `path`, so set `APP_URL` correctly first. The full list is in the [command reference](/reference/commands#mcp-bridge-connect).

Use the `--server` option to connect to another configured server, for example the `ops` server, which carries every tool that is not a route: command, job, action, model, remote and OpenAPI tools. See [where a tool lands](/guide/servers-and-modules#where-a-tool-lands).

## Over stdio

A client on the same machine can run the application as a subprocess instead of reaching it over HTTP. Set `local` to `true` in `config/mcp-bridge.php`, and every configured server is also registered with `laravel/mcp` as a stdio handle, started with `php artisan mcp:start <server>`.

A subprocess carries no token, so there is nobody to run a call as. `security.local_actor` names that user, by id or email; without it every stdio call is refused. The setting is read only over stdio and only when nothing else authenticated the caller, so it changes nothing about the HTTP servers. See [who a call runs as](/guide/security#who-a-call-runs-as).

```bash
php artisan mcp-bridge:connect claude --stdio
php artisan mcp-bridge:connect cursor --stdio --server=ops
```

The first prints a `claude mcp add --transport stdio` line; the others print an `mcpServers` block with `command` and `args`. Both warn when `local` is off or no local actor is named.

## Checking a connection

If a client cannot connect, run:

```bash
php artisan mcp-bridge:doctor
```

It reports whether the package is enabled, whether each server has a path and a registered route, whether every `auth:` guard and `throttle:` limiter named in a server's middleware exists, who stdio calls run as when `local` is on, whether every class in `execution.middleware` exists, whether the manifest is compiled, whether the token and audit tables exist and whether anything is switched off. For symptoms, see [troubleshooting](/guide/troubleshooting).
