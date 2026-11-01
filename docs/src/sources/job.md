# Jobs

Queued jobs become tools for work that should happen in the background: send a digest, recalculate a figure, import a file. The agent is told the work was **accepted** and onto which queue, which is the honest answer, rather than a fabricated result.

## What it exposes

Classes found under `sources.job.paths` (default `['app/Jobs']`). A glob is expanded, so a modular application can name every module's `Jobs` directory at once. The class name comes from the file's own namespace, so an unusual PSR-4 layout is read correctly.

The source is off by default (`sources.job.enabled`).

## What it needs to be opted in

On the job class: `#[McpTool]`, `#[McpScope]` and `#[McpAbility]`. A job without all three is refused at compile time.

The job must also be buildable from an agent's arguments. Its constructor is the schema, and a parameter that cannot be filled from JSON (untyped, a closure, an interface that is not a model) refuses the whole candidate with the parameter named, rather than exposing a tool that fails on its first call.

## Example

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'Recalculate one course.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class RecalculateCourse implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Course $course,
        public CourseStatus $status = CourseStatus::Draft,
        public ?string $reason = null,
        public bool $force = false,
    ) {}

    public function handle(): void
    {
        // ...
    }
}
```

This compiles to `recalculate_course` on the `ops` server, with these inputs:

- `course`, an integer, required: "The id of the Course to use." The job receives the `Course` record, found by its key, not the id. A missing record answers a not-found error.
- `status`, an enum of the backed enum's values, defaulting to `draft`. The job receives the enum case.
- `reason`, an optional nullable string.
- `force`, a boolean.

Typed parameters are coerced to what the constructor declared: a model from its key, a backed enum from its value, a date from its string. The job is built through the container, so a constructor that also takes a service still resolves.

A parameter typed as a [`spatie/laravel-data`](https://github.com/spatie/laravel-data) object is read the same way an action's is: the object's own rules become the tool's inputs, flat, and the job receives the object, built with `validateAndCreate()` from what the agent sent.

A call returns:

```json
{ "status": "queued", "job": "RecalculateCourse", "queue": "mcp-bridge", "id": "9d3c..." }
```

The `id` is this package's, not the queue driver's, and it is what the `job_status` tool takes. Without a description of its own, the tool says it "is accepted for processing rather than finished when this returns; pass the id it returns to job_status to learn what became of it".

## What became of it

Every server that carries a job tool also carries `job_status`, because a tool that answers "accepted" owes the agent a way to ask what happened next. It takes the `id` a job tool returned and answers one of `queued`, `running`, `done` or `failed`, with the times each was reached:

```json
{ "id": "9d3c...", "status": "done", "job": "RecalculateCourse", "tool": "recalculate_course", "queued_at": "...", "started_at": "...", "finished_at": "...", "error": null }
```

- **How it is kept current.** The record is written at dispatch and moved along by middleware the job carries, outermost, so a job refused in the worker by the [re-authorisation check](#authority-outlives-dispatch) is recorded as `failed` with the reason rather than left `queued`.
- **When there is none.** A job that cannot carry middleware returns no `id`, and there is nothing to ask.
- **Who may read it.** Only the actor who queued the job. An id that is somebody else's reads the same as one that never existed.
- **What a failure says.** A failure's message names the application's internals, so it is in `error` only when `security.expose_error_details` is on. Otherwise the text says the application log has the detail.
- **How long it lasts.** The record lives in the cache for `sources.job.status_ttl` (default `24h`), not in a table: the question is asked within minutes of the dispatch, and a row nobody reads twice is not worth pruning.

## The queue

Agent-dispatched work goes to its own queue, `mcp-bridge` by default (`sources.job.queue`), so it cannot starve or poison your application's queues, and so it is separable in Horizon. Run a worker for it.

Override how one job is queued with [`#[McpQueue]`](/reference/attributes#mcpqueue):

```php
#[McpQueue(queue: 'reports', connection: 'redis', delay: 30)]
class RebuildReport implements ShouldQueue { /* ... */ }
```

### Synchronous jobs

`#[McpQueue(sync: true)]` runs the job inside the call and waits, which suits work short enough that an agent waiting is better than an agent being told "accepted" and never learning the outcome. The result is `{ "status": "completed", "job": "SendDigestNow" }`, and a generated description would read "Run the send digest now job and wait for it." A job that does not implement `ShouldQueue` also runs synchronously.

```php
#[McpTool(description: 'Send the digest and wait.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
#[McpQueue(sync: true)]
class SendDigestNow implements ShouldQueue { /* ... */ }
```

## Authority outlives dispatch

Revoking a token should stop work the agent already queued, not only the calls it has still to make. With `sources.job.reauthorize` on (the default), a job that declares `#[McpAbility]` is dispatched with middleware that asks the Gate again, as the original actor, when the worker picks it up. If the account is gone or the ability is lost, the job fails with `JobNoLongerAuthorized` instead of running or silently vanishing.

A job that cannot carry the middleware (it declares `$middleware` as anything but public, so it cannot be given one) is refused at dispatch with `MCP_BRIDGE_SERVER_ERROR`; add the middleware to the job's own `middleware()` method. Synchronous jobs are not re-checked, because they have already run when the answer could change.

## Safety

A job is **not** destructive by default, unlike a command. A job that sends a report and a job that deletes an account are equally opaque from outside, so the declaration decides and the class name is no evidence either way. Mark a destructive job `#[IsDestructive]` to get [confirmation](/guide/security#destructive-calls-and-confirmation).

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `sources.job.enabled` | `false` | Switch the source on. |
| `sources.job.server` | `ops` | The server these tools are served from. |
| `sources.job.queue` | `mcp-bridge` | The queue agent work is dispatched to. |
| `sources.job.paths` | `['app/Jobs']` | Where classes are scanned. |
| `sources.job.timeout` | `30` | Seconds a job tool without `#[McpTimeout]` holds its idempotency key while the call runs. Nothing else reads it, and nothing interrupts a job. Compile time. |
| `sources.job.reauthorize` | `true` | Ask the Gate again when the job runs. Call time. |
| `sources.job.status_ttl` | `24h` | How long `job_status` can answer for a queued job. Call time. |
