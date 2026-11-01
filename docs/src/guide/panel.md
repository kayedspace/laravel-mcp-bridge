# The token panel

A small panel, served by the package, where a signed-in user issues and revokes their own tokens and sees what agents did with them. It needs no Filament and no asset build: a few routes and Blade views, styled on their own, in light and dark.

<img class="screenshot light-only" src="/screenshots/panel-tokens-light.png" alt="The tokens page: four usage figures, then each token with its scopes, expiry, calls and refusals in the last seven days, and a Revoke button.">
<img class="screenshot dark-only" src="/screenshots/panel-tokens-dark.png" alt="The tokens page: four usage figures, then each token with its scopes, expiry, calls and refusals in the last seven days, and a Revoke button.">


`mcp-bridge:token` issues a token for anyone, which is an operator's job. The panel issues one for the person looking at it and only for them, because a token is the credential an agent acts with, and the person it acts as is the one who should hand it out and take it back.

## Reaching it

The page is at `/mcp-bridge` by default, set by `panel.path`. Who may open it:

- In the `local` environment, anyone signed in.
- Anywhere else, only when the application defines a `viewMcpBridge` gate and it allows the user. Until the gate exists, production answers `403` to everyone.
- Once the gate is defined it decides in every environment, local included.

Define the gate in a service provider's `boot` method:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewMcpBridge', fn (User $user): bool => $user->isAdmin());
```

A guest is refused even when the gate would allow one, because a token belongs to someone and the panel issues it for the person signed in.

### Middleware and guard

`panel.middleware` is `['web', Authorize::class]`: the session stack and the gate check above. To take the user from a guard other than the default, add its `auth` middleware before the check:

```php
'panel' => [
    'middleware' => ['web', 'auth:practitioner', \KayedSpace\McpBridge\Http\Middleware\Authorize::class],
],
```

### Switching it off

`panel.enabled` to `false`, or `MCP_BRIDGE_PANEL=false`, makes the routes answer `404`. The switch is read on each request, so it holds with a cached route table.

## Tokens

The tokens page lists the user's tokens with their scopes, client and expiry. Beside each are the calls and refusals it made in the last seven days and the time of its last use, read from the audit trail. Four figures at the top cover every call made as the user, whatever it came with: a package token, a Sanctum token, another guard or a session. They show active tokens, calls, refusals and the last call. The list is paginated, ten tokens to a page.

### Issuing a token

**Issue a token** opens a dialog with five fields:

| Field | What it sets |
| --- | --- |
| Name | What the token is for, so it can be told apart later |
| Scopes | One checkbox per scope a tool on the manifest declares, plus `*` for every scope. Nothing ticked is a token that holds nothing |
| Other scopes | Scopes no tool declares yet, comma separated |
| Client | Which agent the token is for, recorded in the audit trail |
| Expires in days | Defaults to `tokens.default_expiry`. Empty means the token never expires |

<img class="screenshot light-only" src="/screenshots/panel-issue-light.png" alt="The issue dialog: name, scope checkboxes, other scopes, client and expiry in days.">
<img class="screenshot dark-only" src="/screenshots/panel-issue-dark.png" alt="The issue dialog: name, scope checkboxes, other scopes, client and expiry in days.">

The plain token is shown once, on the page after the redirect, and only its hash is stored.

### Revoking a token

**Revoke** marks a token revoked rather than deleting it, so the audit trail can still name it. An agent connected with it stops at its next request, and [work it already queued stops too](/guide/security#identity-and-authority-outlive-dispatch). A token that belongs to someone else is not found.

## Activity

The activity page is the audit trail scoped to the signed-in user: every call an agent made as them, or with one of their tokens, newest first. Each call shows the tool, the outcome and its code, the credential it came with (a package token by its name, or another kind of token), the client, the duration and the redacted arguments, which open underneath. The list is paginated, 25 calls to a page.

<img class="screenshot light-only" src="/screenshots/panel-activity-light.png" alt="The activity page: filters for token, outcome and tool, then a table of calls with their outcome, client, duration and arguments.">
<img class="screenshot dark-only" src="/screenshots/panel-activity-dark.png" alt="The activity page: filters for token, outcome and tool, then a table of calls with their outcome, client, duration and arguments.">

It filters by token, outcome and exact tool name. A token filter naming someone else's token is ignored. The page and the usage figures need `audit.driver` set to `database`; with another driver both are empty.

## Theme

The panel follows the system theme or a choice kept in the browser, and starts in dark. The switch is in the header.

## Users without the trait

A user model without `HasMcpTokens` still sees its tokens and activity. It is told why it cannot issue one, and is refused if it tries, the way `mcp-bridge:token` refuses.

The panel's user must be an Eloquent model, because that is where the token is attached. The trait is what the guard and the scope check use when the token comes back.

## Filament instead, or as well

An application with Filament gets the same page inside its panel from the [Filament plugin](/guide/filament). Both can be on at once; they read and write the same table.
