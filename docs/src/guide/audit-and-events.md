# Audit and events

Every call an agent makes is recorded, whatever became of it. There are two ways to see it: a trail you read afterwards, and an event you react to as it happens.

## What gets recorded

`ToolRunner` records a call once, after it has been authorised, run or refused, so a team asking "what did the agent do" does not need to know whether it went through a route, a command or a job. The entry has three possible outcomes:

| Outcome | Meaning |
| --- | --- |
| `allowed` | The gate passed and the source ran it successfully. |
| `refused` | The gate stopped it: scope, ability, rate limit, kill switch. A confirmation handshake is recorded here too, as the start of a destructive call rather than a non-event, and so is a call an `execution.middleware` class answered before the source ran. |
| `failed` | The gate passed and the call went wrong: the source answered with a failure, or an exception was thrown by a middleware, the source or the shaping. |

"This agent tried and was not allowed" is the entry that matters most, which is why refusals are written down.

The trail is an Eloquent model, so the questions an operator asks are queries:

```php
use KayedSpace\McpBridge\Models\McpAuditEntry;

// Who called what today, and how long it took.
McpAuditEntry::query()->whereDate('created_at', today())->get(['tool', 'actor_id', 'outcome', 'duration_ms']);

// Refusals for one token, newest first.
McpAuditEntry::query()
    ->where('token_type', $token->getMorphClass())
    ->where('token_id', $token->getKey())
    ->where('outcome', 'refused')
    ->latest()
    ->get();
```

## The audit trail

`audit.driver` chooses where entries go:

| Driver | Behaviour |
| --- | --- |
| `database` (default) | A row in `mcp_bridge_audit`. |
| `log` | One `info` line per call to your logger: `mcp-bridge allowed: list_courses`, with the entry as context. For applications that already ship logs somewhere they can query. |
| `none` | Nothing is written. |

A database entry has these columns:

| Column | Contents |
| --- | --- |
| `tool`, `source`, `server` | Which tool, from which source, on which server. |
| `actor_type`, `actor_id` | The actor the call ran as. |
| `token_type` | The morph class of the token that made the call, a package token or a Sanctum access token, so an id is never read against the wrong table. Null for a session user. |
| `token_id` | Which of the actor's tokens made the call: the package token's id, or a Sanctum access token's when the call came without a package token. Null for a session user. One person's Claude and n8n tokens look alike by actor, and revoking the right one starts here. |
| `client` | The name the client declared in its `clientInfo`, which the stateless protocol carries on every request, or `tests` / `mcp-bridge:call` for those paths. |
| `request_id` | A UUID per call, also sent to routes as `X-Request-Id`. |
| `arguments` | The call's arguments, with secrets masked. |
| `outcome`, `error_code` | The outcome and, if any, the code. |
| `duration_ms` | How long the call took. |
| `confirmed` | Whether a confirmation handle accompanied the call. |
| `status` | The HTTP status the source returned, where it returned one. Null for a refusal, since the gate answered and nothing ran to have a status. |
| `created_at` | When. |

The table is indexed for the questions the trail is for:

| Index | Answers |
| --- | --- |
| `tool`, `created_at` | What did this tool do, recently? |
| `actor_type`, `actor_id` | What did this actor do? |
| `token_type`, `token_id` | What did this token do? |
| `outcome`, `created_at` | How many refusals in the last hour? |
| `created_at` | What happened in this window, and what `mcp-bridge:prune-audit` deletes. |

**Secrets are masked by key.** See [redaction](#redaction).

**A failed audit write does not fail the call.** If the audit table is missing, the failure is logged and swallowed. This is a deliberate choice and the uncomfortable one: the tool call has already happened by the time the audit runs, so refusing now would record nothing and still have done the work, and an agent's call failing because of a reporting problem would turn it into an outage. `mcp-bridge:doctor` checks that the table exists.

Keep the table small:

```bash
php artisan mcp-bridge:prune-audit                    # audit.retention_days, default 90
php artisan mcp-bridge:prune-audit --older-than=30d   # whole days
```

Put the tables on another database with `database.connection`, and rename them with `database.table_prefix` (default `mcp_bridge_`). Both are read by the package's models, so you say it once.

## Redaction

`security.redact` (default `password`, `token`, `secret`, `national_id`) is one list with three effects:

- **In what the agent receives**, a key that contains any entry is removed from the result, at any depth, because naming a secret an agent cannot have only invites it to ask another way.
- **In the audit trail**, a key that contains any entry is written as `[redacted]`: the key stays and the value does not, because the fact that one was sent is worth keeping.
- **In the model source's schema**, a column that contains any entry is neither filterable, sortable nor writable, because a filter on a secret is an oracle on its value even when the output never shows it. See [the models page](/sources/model#the-schema-comes-from-the-model).

```php
// config/mcp-bridge.php
'security' => [
    'redact' => ['password', 'token', 'secret', 'national_id', 'iban'],
],
```

Matching is loose and case-insensitive, so `new_password`, `password_hint` and `api_token` are all caught. The first two are read at call time, so a change needs no manifest rebuild. The third shapes the compiled schema, so a change to it needs one: see [keeping the manifest current](/guide/operations#keeping-the-manifest-current).

Redaction is by key name only. A secret an agent passes under an innocuous name is written as it came, so do not design a tool that takes a secret as an argument. The [`ToolCalled` event](#the-event) carries the raw arguments and is not redacted.

## Log context

For the length of a call, three keys sit on Laravel's log context, so every line the route, command or action writes during it names the call that caused it:

| Key | Value |
| --- | --- |
| `mcp.tool` | The tool's name. |
| `mcp.request_id` | The call's request id, the same one the audit entry and the `X-Request-Id` header carry. |
| `mcp.actor` | The actor's id. |

Context rides on a queued job, so a line the job writes later carries them too. The keys are removed when the call ends, whether it succeeded or not.

## The event

`KayedSpace\McpBridge\Events\ToolCalled` is dispatched once per call, whether or not the audit is switched on. The audit answers a question afterwards by reading rows. The event answers it as it happens, which is what a metrics counter, an alert on a run of refusals or a per-tenant usage meter needs, and none of those should have to poll a table.

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use KayedSpace\McpBridge\Enums\Outcome;
use KayedSpace\McpBridge\Events\ToolCalled;

Event::listen(function (ToolCalled $event) {
    if ($event->outcome === Outcome::Refused) {
        Log::warning('Refused '.$event->call->definition->name, [
            'actor' => $event->call->actor->getAuthIdentifier(),
            'ms' => $event->durationMs,
        ]);
    }
});
```

| Property | Type |
| --- | --- |
| `call` | `ToolCall`: the `definition`, `arguments`, `actor`, `requestId`, `clientName`, `confirm` handle and `idempotencyKey`. |
| `result` | `ToolResult`: `text`, `structured`, `isError`, `errorCode`, `meta`. |
| `outcome` | `Outcome::Allowed`, `Outcome::Refused` or `Outcome::Failed`. |
| `durationMs` | Integer milliseconds. |

One event carries the outcome instead of three classes: a listener that cares about refusals filters on it in a line, while one that counts every call would otherwise subscribe three times and keep the list up to date when a fourth outcome appears.

The event carries the raw `ToolCall`, so its `arguments` are **not** masked. [Redact](#redaction) before you log or ship them.

## Detecting an agent call in your own code

Application code can tell an agent call from a REST call, and name the actor, through the facade: see [the facade](/reference/php-api#the-facade).
