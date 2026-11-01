# Operating in production

## Deploy

The server is served from a compiled manifest, so compile it as part of every release, with the same environment the application will run with. `php artisan optimize` does it for you: it runs `mcp-bridge:cache` after `config:cache` and `route:cache`, and `php artisan optimize:clear` runs `mcp-bridge:clear`. A deploy that already runs `optimize` gets a manifest with no extra line:

```bash
php artisan migrate --force
php artisan optimize
```

A deploy that caches piece by piece runs `php artisan mcp-bridge:cache` after `config:cache` instead.

`mcp-bridge:cache` writes `bootstrap/cache/mcp-bridge.php` as a plain exported PHP array, like route caching, so a request costs a file read that OPcache has already warmed rather than a discovery pass. It fails the build with a clear message if two tools want one name.

`mcp-bridge:lint` belongs in CI rather than the deploy, so a declaration that will not work stops the merge instead of the release. It exits non-zero on an error:

```bash
php artisan mcp-bridge:lint
```

## Keeping the manifest current

A connected client is served from `bootstrap/cache/mcp-bridge.php` while that file exists and `discovery.cache` is on. Without the file, or with `discovery.cache` off, the manifest is compiled from the application on first use and kept for the life of the process. Either way, what a process loaded is what it serves until it restarts.

A cached manifest is the thing most often out of date. The symptoms: a new route is missing, a removed route still answers, a changed description is not reflected, a changed scope is not enforced.

- **Rebuild on every deploy**, through `optimize` or after `config:cache`, as above.
- **Rebuild after any change to a declaration or to compile-time configuration** (`mode`, a source's `enabled`, `naming`, `servers`). Those are decided when the manifest compiles.
- **Rebuild after upgrading the package.** A manifest written by a different version is treated as absent, so the server compiles on demand until you rebuild rather than misreading the old file.
- **Reload long-lived processes.** Under Octane, run `php artisan octane:reload` after `mcp-bridge:cache`; restart a stdio server.
- **Go back to compiling on demand** with `php artisan mcp-bridge:clear`, which removes the file. That is the comfortable setting locally.

What does **not** need a rebuild: token scopes and revocation, the kill switch, `security.*` values read at call time (`confirm_handle_ttl`, `expose_error_details`, and `redact` for output and the audit), `output.*` and `audit.*`. `redact` also shapes the model source's schema, which does need a rebuild. The [configuration reference](/reference/configuration) marks every key read at call time.

`mcp-bridge:tools`, `mcp-bridge:call` and `mcp-bridge:describe` read what the server reads. `mcp-bridge:tools --refused`, `mcp-bridge:lint` and `mcp-bridge:export` compile fresh, so they show what a rebuild would produce.

## Checklist

1. **Authentication on every server.** `middleware` on each server in `config/mcp-bridge.php` names a guard you have defined (`php artisan mcp-bridge:doctor` checks).
2. **A shared cache.** Confirmation handles are spent through `Cache::add`, idempotency keys are claimed and job statuses kept in the cache, and each of those holds only in a store every worker shares. Use Redis, the database or Memcached, never the `array` or `null` driver. `mcp-bridge:doctor` fails while `cache.default` resolves to either.
3. **The migrations run.** Tokens, the audit trail, staged uploads and the kill switch each have a table. `mcp-bridge:doctor` checks the `tokens`, `audit` and `kills` tables.
4. **A worker for the agent queue.** Job tools dispatch to `sources.job.queue` (`mcp-bridge`). Without a worker for that queue, jobs are accepted and never run. In Horizon, give it its own supervisor so agent work is visible and bounded.
5. **Narrow namespaces.** Set `sources.command.namespaces` when commands are exposed.
6. **Scoped tokens.** Issue tokens with only the scopes an agent needs and an expiry. Do not leave the 90-day default in place for something that needs a day.
7. **Package tokens for `ops`.** The shipped `ops` server uses `auth:mcp-bridge`, so its tools need package tokens even when `default` uses Sanctum. See [issuing a token](/guide/getting-started#issue-a-token-and-connect).
8. **A destructive floor.** Consider `security.destructive` set to `deny` in production, so no destructive tool is exposed at all. See [destructive calls](/guide/security#destructive-calls-and-confirmation).
9. **Only authenticated routes.** Set `discovery.middleware` to `['auth:*']`, so a route without a guard never becomes a tool. See the [discovery keys](/reference/configuration#discovery).
10. **Policies on route writes.** Consider `security.require_policy`, which refuses a route write whose bound model has no policy. See [requiring a policy](/guide/security#requiring-a-policy).
11. **Tenancy for non-route sources.** With commands, jobs, actions or models on, initialise the tenant in `execution.middleware`, since those tools run no HTTP middleware. See [tenancy](/guide/security#tenancy).
12. **Error details off.** Keep `security.expose_error_details` off. See [what leaves the application](/guide/security#what-leaves-the-application).
13. **An audit destination.** Choose `audit.driver` (`database`, `log` or `none`) deliberately. See [audit and events](/guide/audit-and-events).
14. **`APP_URL`.** `mcp-bridge:connect` builds the client setup from it.
15. **A scheduled prune.** See below.

## Scheduling

Nothing is scheduled for you. Add what your retention policy needs:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mcp-bridge:prune-audit')->daily();
Schedule::command('mcp-bridge:prune-tokens')->daily();
Schedule::command('mcp-bridge:prune-uploads')->hourly();
```

| Command | Deletes | Default window |
| --- | --- | --- |
| `mcp-bridge:prune-audit` | Audit entries | `audit.retention_days` (90) or `--older-than` in days |
| `mcp-bridge:prune-tokens` | Tokens revoked or expired before the window | `--days=30` |
| `mcp-bridge:prune-uploads` | Staged files and rows older than the window | `uploads.ttl` (24h) or `--older-than` |

## The kill switch

When something goes wrong with an agent, a deploy is the slowest possible answer. Switch it off from the shell:

```bash
php artisan mcp-bridge:kill purge_courses
php artisan mcp-bridge:kill --source=command
php artisan mcp-bridge:kill --server=ops
php artisan mcp-bridge:kill --all
php artisan mcp-bridge:kill --list
php artisan mcp-bridge:kill --all --revive
```

A killed tool is neither listed nor callable (`MCP_BRIDGE_DISABLED`, "Do not retry"), and a client that cached its list a minute ago is stopped too, because the switch is checked on both. It is stored in the `mcp_bridge_kills` table with no expiry, so it does not turn itself back on and `cache:clear` does not revive it. Every listing and every call asks, so workers read the table through the cache for ten seconds at a time, and a flush costs one query. The command clears that cached copy, so with a shared cache store every worker and every server sees the change on its next listing or call, and none goes longer than ten seconds without it.

The table comes from the package's migrations. Until it exists nothing is switched off, rather than every call failing for want of the switch's storage, and `mcp-bridge:doctor` reports the missing table. `mcp-bridge:doctor` also shows what is switched off.

To stop one agent rather than a tool, revoke its token: `php artisan mcp-bridge:tokens --revoke=<id>`. For queued jobs, revoking the token or the ability stops work already queued as well, because [the job asks again](/sources/job#authority-outlives-dispatch).

## Watching

- Listen to [`ToolCalled`](/guide/audit-and-events#the-event) for metrics and for alerts on a run of refusals.
- Query the audit table for "who called what, how long it took, whether it was confirmed".
- Rate limits are layered: the server's `throttle:` middleware limits requests, `#[McpRateLimit]` and `sources.command.rate_limit` limit a tool per actor. The package defines `throttle:mcp` (60 a minute) and `throttle:mcp-ops` (20 a minute) per user, falling back to IP, unless you define them yourself.

## Octane and long-lived workers

Every executor captures the request and resolved actor before a call and restores them afterwards, so one call cannot leak its actor into the next. Reloading workers after a rebuild is covered in [keeping the manifest current](#keeping-the-manifest-current).

## Debugging a production surface

```bash
php artisan mcp-bridge:doctor
php artisan doctor --only=kayedspace/laravel-mcp-bridge   # the same checks, through laravel/doctor
php artisan mcp-bridge:tools --refused
php artisan mcp-bridge:call list_courses --as=ops@example.com
php artisan mcp-bridge:call recalculate_stats --arg=course=12 --as=ops@example.com --scopes=ops:write
```

`mcp-bridge:call` goes through the real pipeline, gate and audit included. Be aware that it writes a real audit entry and runs the real tool. It holds every scope unless `--scopes` narrows it, so pass the scopes the agent's token holds to reproduce what that agent sees. `--scopes=` with an empty value holds no scope at all.
