# Filament

One thing connects this package to a Filament panel: a page where a signed-in user issues and revokes their own package tokens. It needs Filament `^5.0`, which the package suggests rather than requires: `composer require filament/filament:"^5.0"` if your application does not have it.

## The tokens page

`mcp-bridge:token` issues a token for anyone, which is an operator's job. The panel page issues one for the person looking at it and only for them, because a token is the credential an agent acts with, and the person it acts as is the one who should hand it out and take it back.

Register the plugin in your panel provider:

```php
use KayedSpace\McpBridge\Filament\McpBridgePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(McpBridgePlugin::make());
}
```

The page appears in the navigation as "MCP tokens" at `/mcp-tokens`. It lists the signed-in user's tokens with their scopes, client, last use, expiry and revocation, and offers two actions:

- **Issue a token.** Name, scopes (a tag per scope; `*` holds every scope and an empty list holds nothing), the client it is for, and an expiry that defaults to ninety days. The plain token is shown once, in a notification that stays until dismissed.
- **Revoke.** Marks the token revoked rather than deleting it, so the audit trail still has something to name. An agent connected with it stops at its next request, and [work it already queued stops too](/guide/security#identity-and-authority-outlive-dispatch).

The page needs the panel's user to be an Eloquent model, which is where the token is attached. It does not need `HasMcpTokens` on that model; the trait is what the guard and the scope check use when the token comes back.

An application without Filament gets the same page from the package's own [token panel](/guide/panel), and both can be on at once.

Nothing else is added to the panel: no resources, no widgets and no call log. Read the audit trail from the database or through [`ToolCalled`](/guide/audit-and-events#the-event).
