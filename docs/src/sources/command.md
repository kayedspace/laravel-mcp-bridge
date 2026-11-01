# Commands

Artisan commands become tools for operations an agent should be able to run: recalculating figures, rebuilding an index, generating a report.

A command brings no middleware, no guard and no policy, so this source is the strictest after models. It is opt-in per command in every mode, cannot be switched to opt-out, must declare its own authority, is destructive until it says otherwise, and runs as the actor.

## What it exposes

Commands registered with the console kernel that extend `Illuminate\Console\Command`. A bare Symfony command carries no attributes, so it has no opt-in to find and is never a tool.

Switch the source on first; it is off by default:

```php
// config/mcp-bridge.php
'sources' => [
    'command' => [
        'enabled' => true,
        'namespaces' => ['stats', 'reports'],   // recommended in production
        // ...
    ],
],
```

## What it needs to be opted in

All three, on the command class:

1. `#[McpTool]`, which is the opt-in.
2. `#[McpScope]`, a token scope.
3. `#[McpAbility]`, a Gate ability.

Missing the last two, the command is refused at compile time and `mcp-bridge:lint` reports an error. It must also not be on the [deny list](#the-deny-list), and its namespace must be allowed by `sources.command.namespaces` (any, by default).

## Example

```php
use Illuminate\Console\Command;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'Recalculate course statistics for a date range.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class RecalculateStats extends Command
{
    protected $signature = 'stats:recalculate
        {course : The course to recalculate}
        {--from= : Start date}
        {--until=}
        {--force : Ignore the cached figures}
        {--tag=* : Limit to these tags}';

    protected $description = 'Recalculate course statistics.';

    public function handle(): int
    {
        // ...
        return self::SUCCESS;
    }
}
```

This compiles to `recalculate_stats` on the `ops` server. A command that only reads says so, and is then not treated as destructive:

```php
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[McpTool(description: 'Print the current statistics.')]
#[McpScope('ops:read')]
#[McpAbility('run-reports')]
#[IsReadOnly]
class ReadStats extends Command { protected $signature = 'stats:show'; /* ... */ }
```

The tool name is the signature: `stats:recalculate` becomes `recalculate_stats`. The description falls back to the command's `$description` when `#[McpTool]` gives none, but write one for agents.

## The schema is the signature

| Signature | Input |
| --- | --- |
| `{course}` | A required `course` string. |
| `{course?}` or a default | An optional input. |
| `{--from=}` | An optional `from` value. |
| `{--force}` | A boolean flag. `false` leaves it out entirely. |
| `{--tag=*}` | A list. |
| `: The course to recalculate` | The input's description. |

Global console options (`--verbose`, `--env` and the rest) are left out.

## How it runs

`CommandExecutor` calls the command through the console kernel, the same entry point `php artisan` uses, with three differences:

- **Interaction is off.** `--no-interaction` is always set, so a command that would prompt fails rather than hangs on a question nobody will answer. `mcp-bridge:lint` reports a command that calls a prompt as `MCP_BRIDGE_LINT_INTERACTIVE`. Add an option instead, or mark the command `#[McpIgnore]`.
- **Output is captured**, not written to a terminal. With `capture_output` on, success returns `{ "output": "..." }`.
- **It runs as the actor.** Inside the command, `auth()->id()` is the actor, not nobody, so a policy check or `authorize()` call sees the real, non-privileged user.

A non-zero exit code is a failure: `MCP_BRIDGE_COMMAND_FAILED`, with the exit code and the last `output_tail_lines` lines of output, because a failing command says what went wrong last.

## Safety defaults

A command is **destructive by default**, the inverse of a `GET` route and deliberate: what a command touches is opaque, so the safe reading is the pessimistic one. With `security.destructive` at `confirm`, a command tool asks for [confirmation](/guide/security#destructive-calls-and-confirmation) before it runs. Say `#[IsReadOnly]` for a command that only reads, or set `destructive_by_default` to `false` if you accept the opposite default.

The source also has a rate limit of `5/minute` per actor per tool by default, overridable per command with `#[McpRateLimit]`.

## The deny list

Static analysis cannot tell what an arbitrary command touches. So the source bounds the blast radius instead of pretending to know:

- A built-in floor of commands that are never exposed: `migrate`, `migrate:*`, `db:wipe`, `db:seed`, `down`, `up`, `tinker`, `key:generate`, `config:clear`, `cache:clear`, `optimize:clear`, `env:decrypt`, `env:encrypt`, `storage:link`, `vendor:publish`, `schema:dump`, `queue:flush`, `queue:forget`, `queue:restart` and `mcp-bridge:*`.
- `sources.command.deny`: patterns you add (`Str::is` syntax). Emptying this list cannot lift the floor.
- `sources.command.allow_denied`: the only escape hatch, one command name at a time.
- `sources.command.namespaces`: an allowlist. The shipped `['*']` lets any namespace opt in, which is acceptable only because exposure is opt-in per command anyway, and an empty list lets none. Naming the namespaces is the production posture.

A denied command is refused even when it carries `#[McpTool]`, and the reason shown is "on the deny list", not "not opted in".

## Progress while it runs

A command that takes thirty seconds would otherwise answer with thirty seconds of silence. When the client sends a progress token with the call, each line the command prints is sent as a `notifications/progress` notification while it runs, numbered in order and carrying that token. The result arrives at the end through the same gate, the same audit and the same captured output as a call without progress. A client that sends no token gets the single answer, because the protocol allows progress only when it was asked for.

A command is synchronous, so it runs inside a fiber that its output suspends on every line; nothing about the command changes. `sources.command.stream_output` (default `true`) turns this off.

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `sources.command.enabled` | `false` | Switch the source on. |
| `sources.command.server` | `ops` | The server these tools are served from. |
| `sources.command.namespaces` | `['*']` | Allowlist of command namespaces; `*` is any, `[]` is none. |
| `sources.command.deny` | `[]` | Extra deny patterns, on top of the floor. |
| `sources.command.allow_denied` | `[]` | Command names allowed despite the deny list. |
| `sources.command.destructive_by_default` | `true` | Whether commands start destructive. |
| `sources.command.timeout` | `60` | Seconds a command tool without `#[McpTimeout]` holds its idempotency key while it runs. Nothing else reads it. Compile time. |
| `sources.command.rate_limit` | `5/minute` | The default per-tool limit. Compile time. |
| `sources.command.capture_output` | `true` | Return what the command printed. Call time. |
| `sources.command.output_tail_lines` | `20` | Lines of output kept on failure. Call time. |
| `sources.command.stream_output` | `true` | Send each printed line as a progress notification while the command runs, when the client sent a progress token. Call time. |

::: info Timeouts are declared, not enforced
`#[McpTimeout]` on a command is reported in the tool's description ("Expect it to take up to 120 seconds") and bounds how long its [idempotency key](/concepts/output-and-errors#retries-and-idempotency-keys) is held while the call runs. It is advisory: a command runs inside the process and is not interrupted, and `mcp-bridge:lint` notes it as `MCP_BRIDGE_LINT_TIMEOUT_ADVISORY`. `sources.command.timeout` only sets that idempotency window for a command without the attribute. Long calls are visible in the audit trail's `duration_ms`.
:::
