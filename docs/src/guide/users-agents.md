# Your users' agents

This page is for an application whose own users connect their own AI agent, such as Claude, ChatGPT, Cursor or n8n, to it. Each user's agent acts as that user, with that user's rights, and nothing more.

## How it works for a user

1. The user gets a token from your application.
2. The user adds your MCP address and that token to their agent.
3. The agent calls your tools. Every call runs as that user, through your middleware, validation and policies.
4. The user can see what the agent did and revoke the token at any time.

Your part is steps 1 and 4, plus telling users what to paste in step 2. The rest is the package.

## Decide who may connect

If MCP access is part of a plan, define an `accessMcp` gate. Every account it denies sees no tools and is refused on every call, whatever token it holds, and access follows the plan as it changes.

```php
use Illuminate\Support\Facades\Gate;

Gate::define('accessMcp', fn (User $user): bool => $user->subscribed());
```

The gate also receives the server's key, so you can keep the `ops` server for staff. The same rule can also be a middleware, on the MCP server or on your own routes with `$request->isMcp()`; see [who may use MCP at all](/guide/security#who-may-use-mcp-at-all) for both and how they differ from the gate.

## Choose how users get a token

| Credential | Best when | Per-tool scopes | Revocable by the user |
| --- | --- | --- | --- |
| [Package tokens](#package-tokens) | You have no API tokens yet, or want agent tokens separate from them | Yes | Yes, in the panel or your own screen |
| [Sanctum tokens](#sanctum-tokens) | Your users already create Sanctum personal access tokens | Yes, as token abilities | Yes, wherever you list Sanctum tokens |
| [OAuth with Passport](#oauth-with-passport) | Users connect from Claude.ai or ChatGPT, which sign in with OAuth instead of a pasted token | Only scopes Passport grants | Yes, by revoking the Passport token |
| [JWT or another guard](#jwt-or-another-guard) | Your API already authenticates with JWT or a custom guard | Yes, if the user model answers `tokenCan()` | Depends on your guard |

Scopes matter only for tools that declare one with `#[McpScope]`. A route tool without a scope is decided by your middleware and policies alone, so it works with every credential above.

## Package tokens

The package's own token type. The user model needs the `HasMcpTokens` trait, and the `/mcp` server already authenticates with it (`auth:mcp-bridge`).

There are three ways to let users issue their own:

- **The token panel.** Open it to every signed-in user by defining the gate in a service provider:

  ```php
  use Illuminate\Support\Facades\Gate;

  Gate::define('viewMcpBridge', fn (User $user): bool => true);
  ```

  Users then issue, revoke and inspect their tokens at `/mcp-bridge`. See [the token panel](/guide/panel).

- **The Filament page,** if your users already work in a Filament panel. See [Filament](/guide/filament).

- **Your own screen.** Issue the token in your own controller and show the plain value once:

  ```php
  $token = $request->user()->createMcpToken('Claude', ['courses:read'], now()->addDays(90));

  return view('tokens.created', ['plainText' => $token->plainText]);
  ```

  Validate requested scopes against the scopes your tools declare, so a user cannot grant themselves one your screen never offered. See [four ways to issue one](/guide/connecting-clients#four-ways-to-issue-one).

## Sanctum tokens

If your users already create personal access tokens, for example on a Jetstream API tokens page, those tokens can connect an agent too.

1. Authenticate the server with Sanctum in `config/mcp-bridge.php`:

   ```php
   'servers' => [
       'default' => [
           'middleware' => ['auth:sanctum', 'throttle:mcp'],
       ],
   ],
   ```

2. Keep `sanctum` in `security.guards`, which it is by default.

3. Issue tokens with abilities named after your scopes. The package checks them through Sanctum's `tokenCan()`:

   ```php
   $plainText = $user->createToken('Claude', ['courses:read'])->plainText;
   ```

A Sanctum token with the `*` ability holds every scope, the same as a package token with `*`.

## OAuth with Passport

Claude.ai custom connectors and ChatGPT connectors prefer to sign the user in with OAuth rather than take a pasted token. `laravel/mcp` provides the OAuth discovery and client registration routes on top of [Laravel Passport](https://laravel.com/docs/passport).

1. Install and configure Passport, so users can approve an authorization request in your application.

2. Register the OAuth routes, for example in `routes/web.php` or `routes/ai.php`:

   ```php
   use Laravel\Mcp\Facades\Mcp;

   Mcp::oauthRoutes();
   ```

   This publishes the `.well-known` metadata clients look for and a dynamic client registration endpoint.

3. Authenticate the server with Passport's guard:

   ```php
   'servers' => [
       'default' => [
           'middleware' => ['auth:api', 'throttle:mcp'],
       ],
   ],
   ```

   and add that guard to `security.guards`, so route tools pass your routes' own `auth:` middleware.

The user then adds your MCP address in their client, signs in to your application, and approves the connection. Nothing is pasted.

`laravel/mcp`'s flow grants the single `mcp:use` scope. Route tools without `#[McpScope]` work as they are. A tool that declares a scope needs that scope on the Passport token, so either keep scoped tools on the [ops server](/guide/servers-and-modules) behind package tokens, or grant the scopes through Passport.

## JWT or another guard

Any guard that authenticates the user from the request works for authentication. Point the server at it and list it in `security.guards`:

```php
'servers' => [
    'default' => [
        'middleware' => ['auth:jwt', 'throttle:mcp'],
    ],
],
'security' => [
    'guards' => ['mcp-bridge', 'jwt'],
],
```

For tools that declare a scope, the package asks the user model `tokenCan($scope)`. A guard that cannot answer it is refused for every scoped tool, because the check [fails closed](/guide/security#a-guard-that-cannot-report-abilities-fails-closed). Answer it from the token's claims, here with `tymon/jwt-auth`:

```php
use Illuminate\Support\Str;

class User extends Authenticatable
{
    public function tokenCan(string $ability): bool
    {
        $granted = (array) auth('jwt')->payload()->get('scopes', []);

        return in_array('*', $granted, true)
            || collect($granted)->contains(fn (string $scope): bool => Str::is($scope, $ability));
    }
}
```

## Tell users what to paste

Users need two things: the address of your MCP server and their token. Show them on the screen where they issue the token. The address is your `APP_URL` followed by the server's path, `/mcp` by default.

**Claude Code**

```bash
claude mcp add --transport http your-app https://your-app.com/mcp \
  --header "Authorization: Bearer <their token>"
```

**Cursor, VS Code and other JSON-configured clients**

```json
{
  "mcpServers": {
    "your-app": {
      "type": "http",
      "url": "https://your-app.com/mcp",
      "headers": { "Authorization": "Bearer <their token>" }
    }
  }
}
```

**Codex**

```bash
export YOUR_APP_MCP_TOKEN=<their token>
codex mcp add your-app --url https://your-app.com/mcp --bearer-token-env-var YOUR_APP_MCP_TOKEN
```

**Gemini CLI and GitHub Copilot CLI**

```bash
gemini mcp add --transport http your-app https://your-app.com/mcp \
  --header "Authorization: Bearer <their token>"
```

Copilot takes the same arguments as `copilot mcp add`.

**Claude.ai and ChatGPT**

Add a custom connector with the address `https://your-app.com/mcp`. With OAuth set up, they sign in; otherwise they add the `Authorization` header in the connector settings.

`php artisan mcp-bridge:connect <client> --token=...` prints the exact setup for `claude`, `codex`, `gemini`, `qwen`, `copilot`, `cursor`, `vscode`, `opencode`, `windsurf`, `chatgpt` and `n8n`, and `json` for any other client, which you can copy into your own instructions page.

## What users can see and stop

- **Activity.** The panel's activity page shows every call an agent made as the user, allowed or refused, with secrets redacted. See [activity](/guide/panel#activity).
- **Revoking.** Revoking a token stops the agent at its next request, and jobs it already queued stop too.
- **Kill switch.** You, as the operator, can switch off one tool or everything with `php artisan mcp-bridge:kill`, without a deploy.

## Before you invite users

- Every tool you expose answers to a policy or middleware that knows who the user is.
- Write tools that delete, refund or email are marked destructive, so the agent asks the user to confirm first.
- `php artisan mcp-bridge:lint` passes, and `php artisan mcp-bridge:tools` lists exactly what you meant to expose.
- The token screen tells users how to connect, and where to revoke.
