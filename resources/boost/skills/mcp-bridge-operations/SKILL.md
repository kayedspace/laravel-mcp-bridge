---
name: mcp-bridge-operations
description: >
  Operate a running kayedspace/laravel-mcp-bridge server: deploy and the
  manifest cache, incidents, the kill switch, tokens and their revocation,
  the audit trail, pruning, and what to check when a client gets 401, 404 or
  500. Use when asked to deploy, diagnose, stop or clean up the application's
  MCP surface. Exposing or changing tools is the mcp-bridge skill.
license: MIT
metadata:
  author: Ali Youssef Kayed
---

# Operating laravel-mcp-bridge

The server serves a compiled manifest, checks authority on every listing and
every call, and records every call. Operating it is keeping those three
true: the manifest current, the authority narrow, the record kept.

## First move in any incident

```bash
php artisan mcp-bridge:doctor
```

It checks every server's guard and rate limiter, the cache store, the
manifest, the tables, the call middleware, and shows what the kill switch
has turned off. Read it before touching anything. When `laravel/doctor` is
installed, `php artisan doctor` runs the same checks beside the framework's
(`--only=kayedspace/laravel-mcp-bridge` narrows to them). Then:

```bash
php artisan mcp-bridge:tools --refused          # what exists, what was found and refused, and why
php artisan mcp-bridge:call <tool> --as=ops@example.com --scopes=<scope>   # the real pipeline, as that user
```

## Stopping something now

A deploy is the slowest answer. The kill switch is immediate, survives
`cache:clear`, and stops clients that cached their tool list:

```bash
php artisan mcp-bridge:kill purge_courses        # one tool
php artisan mcp-bridge:kill --source=command     # every tool from a source
php artisan mcp-bridge:kill --server=ops         # every tool on a server
php artisan mcp-bridge:kill --all                # the whole surface
php artisan mcp-bridge:kill --list
php artisan mcp-bridge:kill --all --revive
```

To stop one agent rather than a tool, revoke its token:

```bash
php artisan mcp-bridge:tokens                    # who holds what, last used, state
php artisan mcp-bridge:tokens --revoke=<id>
```

A revoked token stops at the agent's next request, and jobs it already
queued stop too, because a job asks the Gate again when it runs.

## Deploy

```bash
php artisan migrate --force
php artisan optimize        # writes bootstrap/cache/mcp-bridge.php after config:cache and route:cache
```

A deploy that caches piece by piece runs `php artisan mcp-bridge:cache`
after `config:cache`. Under Octane, `php artisan octane:reload` after the
rebuild. `mcp-bridge:lint` belongs in CI, not the deploy: it exits non-zero
on a declaration that will not work.

The manifest is what goes stale. Rebuild after any change to a declaration
or to compile-time configuration (`mode`, a source's `enabled`, `naming`,
`servers`) and after upgrading the package. Token scopes, revocation, the
kill switch and `security.*`, `output.*` and `audit.*` values are read at
call time and need no rebuild.

## What production needs

- **A guard on every server.** Both shipped servers use `auth:mcp-bridge`.
  A server switched to `auth:sanctum` needs Sanctum installed or every
  request answers 500.
- **A shared cache store.** Confirmation handles, idempotency keys and job
  statuses live there. `doctor` fails on `array` and `null`.
- **The migrations.** Tokens, audit, uploads and kills each have a table.
- **A worker for the `mcp-bridge` queue** when jobs are exposed, as its own
  Horizon supervisor so agent work is visible and bounded.
- **A `viewMcpBridge` gate** when the token panel at `/mcp-bridge` is on,
  or it answers 403 to everyone outside `local`. `MCP_BRIDGE_PANEL=false`
  turns it off.
- **Scheduled pruning**, since nothing is scheduled for you:

```php
Schedule::command('mcp-bridge:prune-audit')->daily();     // audit.retention_days, default 90
Schedule::command('mcp-bridge:prune-tokens')->daily();    // revoked or expired, --days=30
Schedule::command('mcp-bridge:prune-uploads')->hourly();  // staged files past uploads.ttl
```

## Reading a symptom

| A client gets | Look at |
| --- | --- |
| `401` on everything | The server's guard does not read the token. Package tokens (`mcpb_`) need `auth:mcp-bridge`; a Sanctum token needs a server switched to `auth:sanctum`. `mcp-bridge:tokens` shows whether the token is revoked or expired. |
| `404` on the endpoint | `enabled` is off, the server has no `path`, or the path differs. `doctor` reports a server with no route. |
| `500` on the first request | A guard or rate limiter in the middleware is not defined. `doctor` names it. |
| A tool is missing | `mcp-bridge:tools --refused` says why. Then: a stale manifest, a killed tool, or a token without the scope, in that order of likelihood. |
| `MCP_BRIDGE_SCOPE` | The token lacks the scope the tool declares. Issue a new token; scopes are not edited in place. |
| `MCP_BRIDGE_DISABLED` | The kill switch. `mcp-bridge:kill --list`. |
| `MCP_BRIDGE_RATE_LIMITED` | `throttle:mcp` is 60 a minute and `throttle:mcp-ops` 20, per user; `#[McpRateLimit]` and `sources.command.rate_limit` are per tool per actor. |
| A removed route still answers | The cached manifest. Rebuild and reload workers. |

## The record

Every call, refused or not, is a row in `mcp_bridge_audit` (or a log line
with `audit.driver` set to `log`): tool, actor, token, client, request id,
redacted arguments, outcome, status and duration. `ToolCalled` fires for
every row, which is where metrics and alerts on a run of refusals belong.
Log lines written during a call carry `mcp.tool`, `mcp.request_id` and
`mcp.actor` in their context, and a queued job keeps them.

## Rules

- **Never weaken an exposure default to make something work.** `mode`,
  `require_scope_for_non_http`, `require_policy` and `destructive` are the
  settings an incident will ask about later.
- **Issue tokens with the scopes the agent needs and an expiry.** The
  default is 90 days; a token for a one-off task gets a day.
- **Keep `security.expose_error_details` off** in production. The audit
  trail has the detail; the agent does not need it.
- **Do not edit the manifest file.** It is a build artefact; rebuild it.
- **Do not delete audit rows by hand.** `mcp-bridge:prune-audit` keeps the
  retention policy honest and visible.
