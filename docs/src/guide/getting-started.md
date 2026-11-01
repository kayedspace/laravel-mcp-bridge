# Getting started

This page takes an existing Laravel application from nothing to an agent calling one of its endpoints. It assumes you already have an API route backed by a controller. Adding the package to an application with a large existing API? Read [adopting it in an existing application](/guide/adopting) alongside this page.

## Requirements

PHP 8.3 or later, Laravel 12.41.1 or later (or 13.x) and [`laravel/mcp`](https://github.com/laravel/mcp) `^1.1`, which Composer installs for you. The package speaks MCP revision 2026-07-28, which is stateless: nothing here depends on a session.

## Install

```bash
composer require kayedspace/laravel-mcp-bridge
php artisan mcp-bridge:install
```

`mcp-bridge:install` publishes `config/mcp-bridge.php`, runs `migrate` and, in production, compiles a first [manifest](/concepts/how-it-works#glossary). The service provider is discovered automatically. The migrations create three tables, prefixed `mcp_bridge_`: tokens, audit and uploads.

If you would rather do each step yourself:

```bash
php artisan vendor:publish --tag=mcp-bridge-config
php artisan migrate
php artisan mcp-bridge:cache    # production: locally, tools compile on demand
```

::: tip Own your migrations
To publish the migrations and edit them, run `php artisan vendor:publish --tag=mcp-bridge-migrations`, then call `KayedSpace\McpBridge\McpBridgeManager::ignoreMigrations()` in a service provider so the package's own copies stop loading.
:::

## Expose one endpoint

An HTTP route is the one [source](/concepts/how-it-works#glossary) that is opt-in by attribute in the default `opt-in` mode. Put `#[McpTool]` on the controller action:

```php
use KayedSpace\McpBridge\Attributes\McpTool;

class CourseController extends Controller
{
    #[McpTool(description: 'List published courses with optional title search.')]
    public function index(CourseIndexRequest $request)
    {
        // unchanged
    }
}
```

The description is not decoration. An agent chooses a tool and fills its arguments from that text, so write what the tool does and when to use it. A tool without one gets a generated description, and [`mcp-bridge:lint`](/reference/commands#mcp-bridge-lint) warns about it.

The route must sit under a prefix listed in `discovery.prefixes`, which defaults to `api`, and must be a controller action rather than a closure.

## Expose more at once

Marking routes one by one is the default, not the only way. The `mode` setting decides which discovered routes become tools:

| Mode | Routes exposed |
| --- | --- |
| `opt-in` (default) | Only routes marked with `#[McpTool]` |
| `read-only` | Marked routes, plus every discovered `GET` route |
| `opt-out` | Every discovered route, except those marked `#[McpIgnore]` |

```dotenv
MCP_BRIDGE_MODE=opt-out
```

The mode applies to routes only. Commands, jobs, actions and models stay opt-in per class in every mode, because they have no middleware or policy to inherit. See [routes follow the mode](/guide/security#routes-follow-the-mode).

## Two servers

The package serves two endpoints. `/mcp` carries your routes and resources, for agents acting as your users. `/mcp/ops` carries commands, jobs, actions, models, remote servers and OpenAPI documents, for operators. Each has its own rate limiter, so an operator's tools never appear in a product agent's catalog. See [servers and modules](/guide/servers-and-modules).

## See what became a tool

```bash
php artisan mcp-bridge:tools
php artisan mcp-bridge:tools --refused
```

`mcp-bridge:tools` lists what became a tool, which source it came from and which server serves it. `--refused` lists each [candidate](/concepts/how-it-works#glossary) that was found and not exposed, with the reason, and answers most "why is my tool missing" questions outright.

Locally there is nothing to rebuild: without a cached manifest, every enabled source is compiled on first use.

## Let the user model hold tokens

Add the trait to the user model agents connect as:

```php
use KayedSpace\McpBridge\Concerns\HasMcpTokens;

class User extends Authenticatable
{
    use HasMcpTokens;
}
```

It is what lets the model hold a [package token](/concepts/how-it-works#glossary), and what `mcp-bridge:call` attaches its scopes to. It declares no `tokenCan()`, so it sits beside Sanctum's `HasApiTokens` on the same model. Add it whichever way agents authenticate: both shipped servers accept package tokens, and `ops` accepts nothing else.

## See a tool work

Call the tool the way an agent would, before any client or token exists:

```bash
php artisan mcp-bridge:call list_courses --arg=per_page=2
```

This goes through the same runner a connected client uses: authorisation, execution, shaping and audit. It prints the actor it runs as and the scopes it holds (once the model has the trait above), then the text the agent reads and the structured content it receives.

- **Who it runs as.** The user with id `1` by default. When there is no user `1`, or you want someone else, name the user with `--as=you@example.com` or `--as=42`.
- **What it holds.** Every [scope](/concepts/how-it-works#glossary) by default. Pass `--scopes=courses:read` to see what a narrower token would get. The scopes ride on an unsaved package token, so this needs the trait above.

That is a working tool, reached through the real pipeline, before any authentication or client setup. The rest of this page is about letting a real client reach it.

## Choose how agents authenticate

Every call runs as a real user, and the server's middleware decides who that is. Read [the security model](/guide/security) for what a token grants and why the rules are as they are.

### Package tokens

The package ships its own tokens and guard, and both shipped servers use them: `default` and `ops` carry `auth:mcp-bridge`. There is nothing to change. The package registers the `mcp-bridge` guard and the `mcp` and `mcp-ops` rate limiters for you. An application that defines any of them itself keeps its own definition.

### Sanctum instead

Point the `default` server at the Sanctum guard and issue Sanctum tokens:

```php
// config/mcp-bridge.php
'servers' => [
    'default' => [
        // ...
        'middleware' => ['auth:sanctum', 'throttle:mcp'],
    ],
],
```

::: warning Sanctum must be installed first
If the `sanctum` guard is not defined, every request to `/mcp` answers `500`. Run `php artisan install:api`, which installs Sanctum, before switching.
:::

On `default` the scope check then asks the user's `tokenCan()`, which Sanctum's `HasApiTokens` answers. The `ops` server keeps `auth:mcp-bridge` either way, so tools served there need package tokens.

## Issue a token and connect

Issue the token the server you connect to accepts. Either way the token is shown once, and only its hash is stored.

### With package tokens

```bash
php artisan mcp-bridge:token you@example.com --name=Claude --scopes=courses:read
```

### With Sanctum

Issue a Sanctum token with the scopes as its abilities, from `php artisan tinker` or your own token screen:

```php
$user->createToken('claude', ['courses:read'])->plainText;
```

The Sanctum guard does not read package tokens, so `mcp-bridge:token` is not for this path, and a package token sent to a server on `auth:sanctum` is answered `401`. Tools on `ops` need a package token from the command above, because that server keeps `auth:mcp-bridge`.

### Connect

```bash
php artisan mcp-bridge:connect claude --token=mcpb_your_token_here
```

It prints the client setup ready to paste, for `claude`, `cursor`, `vscode`, `chatgpt` or `n8n`, with whichever token you pass. For Claude Code it is a single line:

```bash
claude mcp add --transport http my-app https://my-app.test/mcp \
  --header "Authorization: Bearer mcpb_your_token_here"
```

Then ask the client to list the courses. The call arrives at `/mcp`, becomes an in-process request to your route, and returns a compact, redacted, paginated answer. See [connecting a client](/guide/connecting-clients) for the details of each client and of scopes.

## Before you ship

```bash
php artisan mcp-bridge:lint
php artisan mcp-bridge:doctor
```

`lint` exits non-zero on anything that will not work as declared, so it belongs in CI. `doctor` checks the configuration, guards, limiters, cache store, manifest and tables, and is the first thing to run when a client cannot connect. With Laravel's own [`laravel/doctor`](https://github.com/laravel/doctor) installed, the same checks appear in `php artisan doctor` beside the framework's, so one command covers the box. Outside production it reports a missing cached manifest as fine, because tools compile on demand; in production it fails until the file exists.

In production, cache the manifest in your deploy: `php artisan optimize` runs `mcp-bridge:cache` for you. While `bootstrap/cache/mcp-bridge.php` exists a connected client is served from it, so rebuild whenever a declaration changes. See [operating in production](/guide/operations#keeping-the-manifest-current).

## Next

- Read the [security model](/guide/security) before exposing a command, job, action or model.
- Learn what [the pipeline](/concepts/how-it-works) does with each call.
- Browse the [eight sources](/sources/).
- Pin the contract in your test suite with the [testing helpers](/guide/testing).
