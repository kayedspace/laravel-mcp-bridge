# Output and errors

An agent pays for every byte it reads, and a wrong answer is worse than a large one. Output is shaped so that the answer fits, and failures are mapped so that the agent can act on them.

## What a successful call returns

A call returns text and structured content, and both carry the answer. For a list the structured content is:

```json
{
  "data": [ { "id": 1, "title": "Cardiology Basics" }, { "id": 2, "title": "Paediatrics" } ],
  "pagination": { "current_page": 1, "per_page": 15, "total": 2 }
}
```

and the text is a summary line, "Returned 2 of 2 items.", followed on the next line by that same JSON. The protocol asks a tool that sends structured content to put the serialised form in text as well, so a client that reads only text content is not left with the summary alone. For a single record the structured content is `{"data": {...}}` and the summary names the tool. A `204` or an empty body answers "The call succeeded and returned no content." (or "... completed." for a destructive tool), with nothing to repeat.

`output.structured` decides whether `structuredContent` is sent at all. Turning it off costs a client parsing convenience, never the data.

### The shaping steps

`OutputShaper` runs these in order on a successful result:

1. **Unwrap** the `data` envelope that API Resources and collections add.
2. **Redact.** Remove every key that contains an entry of `security.redact`. See [redaction](/guide/audit-and-events#redaction).
3. **Select.** If the tool declares [`#[McpOutput(fields: [...])]`](/reference/attributes#mcpoutput), keep only those keys, on a record or on every record of a list.
4. **Trim.** Cut a list to `maxItems` from `#[McpOutput]`, or `output.max_items` (default 25).
5. **Compact.** Drop `null` values when `output.drop_nulls` is on (default) and truncate strings longer than `output.max_string` (default 2000).
6. **Fit to a budget.** `output.token_budget` (default 8000 tokens, estimated at four bytes a token) drops rows from the end of a list until it fits, because `max_items` counts rows and an agent pays for bytes. One row always survives, since an empty list where rows exist reads as "there are none". A single record is never cut in half. `0` turns the budget off.
7. **Keep pagination.** The keys `next_cursor`, `prev_cursor`, `has_more`, `current_page`, `last_page`, `per_page`, `total`, `from` and `to` survive the trimming and are returned under `pagination`.

When anything was trimmed the result carries `truncated` in its metadata and the text says "The list was trimmed; narrow it with filters or paging."

### Advertised output schema

A tool advertises an output schema only when the shape is certain, which is when at least one of these holds:

- it pages: it has a `page`, `per_page` or `cursor` input;
- it declares `#[McpOutput(fields: ...)]`;
- Scramble inferred its response, with `integrations.scramble.responses` on.

What the schema says:

- It describes the `data` envelope, as a list for a tool that pages and a single record otherwise, plus `pagination` for a tool that pages.
- Declared fields are named in the record's description and are not typed.
- Per-field types come only from Scramble's inference, never from a guess, because deriving them means reading an API Resource's `toArray()`, which is the job of an inference engine. See [typed output from inferred responses](/guide/documentation-packages#typed-output-from-inferred-responses).
- A tool whose shape is unknown advertises nothing rather than promising something it might not send.

## Errors

A failure is mapped to a message the agent can act on. The agent receives the message text; the code is for you, and appears in `mcp-bridge:call`, the audit trail, the `ToolCalled` event and your tests.

| Situation | Code | What the agent is told |
| --- | --- | --- |
| Validation failed (422) | `MCP_BRIDGE_VALIDATION` | The message, then one `field: message` line per failing field, with the errors also in structured content. |
| Not authenticated (401) | `MCP_BRIDGE_UNAUTHENTICATED` | The connection needs to be re-authorised. |
| Not allowed (403) | `MCP_BRIDGE_FORBIDDEN` | Not allowed for this user. Do not retry. |
| Missing record (404) | `MCP_BRIDGE_NOT_FOUND` | Not found. A tool that takes a record's key (a route parameter or a model key) adds "Use the matching list tool to find a valid id." |
| Rate limited (429) | `MCP_BRIDGE_RATE_LIMITED` | Retry after a number of seconds. |
| Maintenance mode (503) | `MCP_BRIDGE_UNAVAILABLE` | The application is unavailable. Retry later. |
| Server error (5xx) | `MCP_BRIDGE_SERVER_ERROR` | "The application failed to handle the call." No internals, unless `security.expose_error_details` is on, in which case the message your exception handler rendered is appended. |
| The route rejected the request (400) | `MCP_BRIDGE_BAD_REQUEST` | The route's own message. A rejected filter, sort, include or field, from Spatie or from `ApiQuery`, arrives here with the message that names the allowed values. |
| A route returned HTML | `MCP_BRIDGE_NON_JSON` | The route did not return JSON. |

Executors that already know the code say so rather than having it guessed back from a status. Command, job and remote failures use `MCP_BRIDGE_COMMAND_FAILED`, `MCP_BRIDGE_JOB_FAILED`, `MCP_BRIDGE_REMOTE_ERROR` and `MCP_BRIDGE_REMOTE_UNAVAILABLE`. The gate adds `MCP_BRIDGE_SCOPE`, `MCP_BRIDGE_FORBIDDEN`, `MCP_BRIDGE_DISABLED`, `MCP_BRIDGE_RATE_LIMITED` and the confirmation codes, an argument no field declares is refused with `MCP_BRIDGE_UNKNOWN_ARGUMENT` and the names the tool accepts, and an idempotency key still in use answers `MCP_BRIDGE_IN_FLIGHT`. The full list is in the [error codes reference](/reference/error-codes).

`security.expose_error_details` is for local debugging; leave it off elsewhere.

An exception thrown inside the call pipeline, by an [`execution.middleware`](/reference/php-api#middleware-around-a-call) class, the source or the shaping, never escapes to the client:

- `ToolRunner` catches it and reports it to the application's exception handler, so it reaches your logs and error tracker.
- The agent receives `MCP_BRIDGE_SERVER_ERROR`, with the detail only when `security.expose_error_details` is on.
- The audit trail records the call as `failed`.
- The idempotency key is released, so a retry runs.

A confirmation request is **not** an error. It returns normally with the preview text and a handle, as described in the [security model](/guide/security#destructive-calls-and-confirmation).

## Retries and idempotency keys

A dropped connection is the ordinary case, not the adversarial one. An agent cannot tell a lost response from a call that never arrived, so it retries, and a second enrolment or a second charge is the result. A confirmation handle already protects destructive tools; an idempotency key protects the writes that create something.

Every tool that is not read-only offers an optional `idempotency_key` input while `execution.idempotency.enabled` is on (the default). An agent that sends a key and then retries the same call gets the first call's answer instead of a second execution.

- **What the key is bound to.** The tool, the actor and the exact arguments. One agent's key cannot collide with another's, the same key on a different tool is a different operation, and a key reused with different arguments is a different call rather than a replay.
- **Replay happens after the gate.** The authority checks run first on every call, replays included. A token that has since lost the scope is refused rather than handed what the token that held it received, and a refusal claims no key, which is what keeps a destructive tool callable with one: the first call is answered with a confirmation handle, and the second gets to run. The cost is that a retry spends a rate-limit slot.
- **Only a success is remembered.** A call that failed releases its key, so an agent that corrects a validation error and retries with the same key gets the corrected call run, not the old error.
- **A call still running answers `MCP_BRIDGE_IN_FLIGHT`.** While the first call runs, its key is held for at most the tool's timeout, so a retry is told to wait rather than run the write a second time.
- **A replay is marked.** It carries `meta.replayed`, and both calls are audited and fire `ToolCalled`, because an agent that retried is something an operator should be able to see.
- **How long it lasts.** A successful answer is kept for `execution.idempotency.ttl` (default `24h`), in the cache.

Unlike a confirmation handle, this fails open. A cache that cannot answer means a write may run twice; refusing instead would mean an agent cannot write at all, and for an operation whose point is to happen, not happening is not the safer outcome.

## Deprecation

A tool that should be retired but not removed keeps working and tells the agent to stop reaching for it:

```php
#[McpTool(description: 'List courses, the old way.')]
#[McpDeprecated('Use list_courses instead.', until: '2.0')]
public function legacy(): JsonResponse { /* ... */ }
```

The notice is appended to the description the agent reads.
