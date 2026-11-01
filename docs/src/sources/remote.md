# Remote servers

A remote source re-exports tools from another MCP server through this one. The point is one endpoint and one token for an agent that would otherwise hold several.

The remote server's own schema is used as it stands, because it is already a declaration. Nothing is inferred.

## What it exposes

Every tool the configured remote servers advertise at compile time, optionally narrowed with `only` and `except`. The remote is reached through `laravel/mcp`'s own web client.

A remote that cannot be reached when you run `mcp-bridge:cache` yields no tools rather than failing the build: the rest of the surface is still correct. Run `mcp-bridge:doctor` or the cache again once it is reachable. At call time an unreachable remote answers `MCP_BRIDGE_REMOTE_UNAVAILABLE` (HTTP 502).

## What it needs to be opted in

The source is off by default. Opting in is configuration: enable it and list a server under `sources.remote.servers` with at least a `url`.

Authority is **this application's**, not the remote's. A re-exported tool must declare a scope and an ability in its server's config block. A block with neither is refused like any other non-HTTP candidate, with "needs both #[McpScope] and #[McpAbility]". The token the remote is reached with belongs to this application, and an agent that may call your `billing_list_invoices` never learns the credential behind it.

## Example

```php
// config/mcp-bridge.php
'sources' => [
    'remote' => [
        'enabled' => true,
        'servers' => [
            'billing' => [
                'url' => env('BILLING_MCP_URL'),
                'token' => env('BILLING_MCP_TOKEN'),
                'scopes' => ['billing:read'],      // what a caller of ours needs
                'abilities' => ['use-billing'],
                'only' => ['*'],                   // which of its tools to re-export; [] is none
                'except' => [],
                'prefix' => 'billing',             // keeps two remotes from colliding
            ],
        ],
    ],
],
```

If `billing` advertises `list_invoices` and `void_invoice`, this compiles to `billing_list_invoices` and `billing_void_invoice`.

| Server key | Meaning |
| --- | --- |
| `url` | The remote MCP endpoint. Required. |
| `token` | Sent as a bearer token. |
| `headers` | Extra headers, as a name to value map. |
| `timeout` | Request timeout in seconds. |
| `scopes`, `abilities` | The authority an actor of this application needs. Required. |
| `only`, `except` | Names of the remote's tools to re-export or to skip. `only` is an allowlist: `*`, or leaving it out, re-exports everything, and an empty list re-exports nothing. `except` removes from that. |
| `prefix` | Prepended to every name, snake-cased. Defaults to the server key. An empty string means no prefix. |

Names are prefixed by default because two remotes each having a `list_invoices` is the normal case, not the exception.

## How it behaves

- **Safety hints are the remote's.** `readOnlyHint`, `destructiveHint`, `idempotentHint` and `openWorldHint` are trusted as hints and nothing more, because this application is the one being asked to confirm. A hint the remote does not send is taken in its worst case: a missing `destructiveHint` makes the tool destructive, so it [asks for confirmation](/guide/security#destructive-calls-and-confirmation).
- **The description is the remote's words**, capped at 1000 characters, with ". Runs on the [billing] server." appended. A tool description is model input, and a remote that wanted to steer your agent would do it there, so re-export only servers you would let write into your own prompt.
- **The answer is passed through.** The remote already shaped its result for an agent, so structured content is returned as it came (text content is wrapped as `{ "text": ... }`). A remote error is reported as `MCP_BRIDGE_REMOTE_ERROR` with the remote's message.
- **Identity is not forwarded.** The remote has never heard of this agent and would have no way to check it, so it is reached with this application's credential.
- **The gate still runs.** Scopes, abilities, the rate limit, the kill switch and the audit trail apply to a re-exported tool like any other.
- **Groups.** A remote tool's group is its server key, `billing` here.

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `sources.remote.enabled` | `false` | Switch the source on. |
| `sources.remote.servers` | `[]` | Remote servers, keyed by name. |

The shipped `ops` server lists `remote` among its sources, so re-exported tools land on `/mcp/ops`, behind `auth:mcp-bridge`. Set `sources.remote.server` to place them elsewhere, or list a remote's key in a server's `groups` to move just that remote; the full order is in [where a tool lands](/guide/servers-and-modules#where-a-tool-lands).
