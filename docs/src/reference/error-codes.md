# Error codes

Every refusal and failure carries a stable code, so you can match on it in tests, alerts and the audit trail. The agent itself receives the message text.

Codes appear in `mcp-bridge:call`, `ToolResult::$errorCode` (tests), the audit table's `error_code` column and the `ToolCalled` event.

## Refusals at compile time

These explain why a candidate was found and not exposed. They show in `mcp-bridge:tools --refused`, and `assertToolRefused()` accepts any of them as its `$code`.

| Code | Meaning |
| --- | --- |
| `MCP_BRIDGE_NOT_EXPOSED` | Not opted in. "Add #[McpTool] to opt it in." Deliberate, and only shown by the lint with `--all`. |
| `MCP_BRIDGE_IGNORED` | The candidate carries `#[McpIgnore]`, or your documentation excludes it. |
| `MCP_BRIDGE_SOURCE_REFUSED` | A source or the authority rule refused it. The message names the cause: a missing scope or ability, a denied command, a command outside the allowed namespaces, a model with no policy or a silent policy, or a signature that cannot be built from JSON. The lint reports it as an error. |
| `MCP_BRIDGE_NAME_TAKEN` | An earlier candidate already holds the tool name this one compiles to. The message names the holder and the fix: give it `#[McpTool(name: ...)]`, or a route override. The build succeeds and every other tool is served. The lint reports the collision as `MCP_BRIDGE_LINT_COLLISION`. See [collisions](/concepts/naming#collisions). |
| `MCP_BRIDGE_INVALID_RATE_LIMIT` | The rate limit the tool would run with cannot be read, so the tool is not served rather than served with no limit. The message names the value and where it was set: `routes.<name>.rate_limit`, `#[McpRateLimit]` or `sources.<key>.rate_limit`. Asked only of a candidate nothing else refused. The lint reports it as an error. |

## Refusals at call time

Returned by the gate before the source runs.

| Code | Meaning |
| --- | --- |
| `MCP_BRIDGE_DISABLED` | The tool, its source or its server is switched off with `mcp-bridge:kill`. "Do not retry." |
| `MCP_BRIDGE_SCOPE` | The actor's token lacks a required scope ("needs the scope"), the caller is unauthenticated, the actor holds no token ("holds no token"), or the guard cannot report token abilities. See [fails closed](/guide/security#a-guard-that-cannot-report-abilities-fails-closed). |
| `MCP_BRIDGE_ACCESS` | The application's `accessMcp` gate denied this account, so it may use none of this server's tools. "Do not retry." |
| `MCP_BRIDGE_FORBIDDEN` | The Gate denied a declared ability. Also used when a route, action or model policy answers 403. "Do not retry." |
| `MCP_BRIDGE_RATE_LIMITED` | The per-tool limit was hit. Includes the seconds to wait. Also used for a 429 from a route. |
| `MCP_BRIDGE_INVALID_RATE_LIMIT` | The tool's rate limit cannot be read. Only a manifest built before the compiler refused such a tool can carry one; rebuild it. "Do not retry." |
| `MCP_BRIDGE_UNKNOWN_ARGUMENT` | An argument no field of the tool declares. The message names the arguments the tool accepts, so the agent can correct the call. Checked after the gate, so only a caller allowed to run the tool learns them. A tool that declares no inputs lets everything through, because a remote tool with a free-form schema looks the same as one with none. |
| `MCP_BRIDGE_CONFIRMATION_REQUIRED` | Not an error. A destructive tool answered with a preview and a handle. `isError` is `false`. |
| `MCP_BRIDGE_CONFIRMATION_EXPIRED` | The handle was already used, has expired, was forged, or the arguments, tool or actor changed. Call again without it. |

## Refusals after the gate

Returned once the gate has agreed, before the source runs.

| Code | Meaning |
| --- | --- |
| `MCP_BRIDGE_IN_FLIGHT` | A call carrying this `idempotency_key` and these arguments is still running. The claim lasts at most the tool's timeout, so wait and retry with the same key, or send a new one. See [retries and idempotency keys](/concepts/output-and-errors#retries-and-idempotency-keys). |

## Failures while running

Returned after a source ran, mapped by `ErrorMapper`.

| Code | Meaning |
| --- | --- |
| `MCP_BRIDGE_VALIDATION` | Validation failed (422). The message lists `field: message` lines and the structured content carries `errors`. |
| `MCP_BRIDGE_UNAUTHENTICATED` | The route answered 401. |
| `MCP_BRIDGE_UNAVAILABLE` | The route answered 503, which is maintenance mode holding the sub-request as it holds any request. Retry later. |
| `MCP_BRIDGE_NOT_FOUND` | A missing record (404). A tool that takes a record's key (a route parameter or a model key) adds a hint to use the matching list tool. |
| `MCP_BRIDGE_BAD_REQUEST` | The route answered 400 and the message is passed through. A rejected filter, sort, include or field, from Spatie or from `ApiQuery`, arrives here, and the message names the allowed values. |
| `MCP_BRIDGE_NON_JSON` | A route or an OpenAPI operation answered with something that is not JSON. |
| `MCP_BRIDGE_SERVER_ERROR` | A 5xx, or an unclassified failure. Never carries internals unless `security.expose_error_details` is on. Also an exception thrown by a call middleware, the source or the shaping (recorded as `failed`, and the idempotency key released), a class in `execution.middleware` that does not exist (named in the message), a source that is not registered, or a job that cannot carry the re-authorisation check. |
| `MCP_BRIDGE_COMMAND_FAILED` | A command could not run, or exited non-zero. Carries the exit code and the output tail. |
| `MCP_BRIDGE_JOB_FAILED` | A job could not be dispatched. |
| `MCP_BRIDGE_REMOTE_ERROR` | A remote tool reported an error. Carries the remote's message. |
| `MCP_BRIDGE_REMOTE_UNAVAILABLE` | A remote server or an OpenAPI document's API could not be reached (HTTP 502). |

## Lint findings

`mcp-bridge:lint` reports findings with a level. It exits non-zero only when an error is present.

| Code | Level | Meaning |
| --- | --- | --- |
| `MCP_BRIDGE_SOURCE_REFUSED` | error | Something that is meant to be exposed will not be. |
| `MCP_BRIDGE_LINT_COLLISION` | error | Two candidates compile to one tool name. |
| `MCP_BRIDGE_INVALID_RATE_LIMIT` | error | A rate limit that cannot be read, so the tool is not served. |
| `MCP_BRIDGE_LINT_INTERACTIVE` | error | A command calls a prompt, and an agent cannot answer one. |
| `MCP_BRIDGE_LINT_NO_DESCRIPTION` | warning | A description will be generated. |
| `MCP_BRIDGE_LINT_DIVERGENCE` | warning | A documentation attribute and an MCP attribute describe the same field differently. |
| `MCP_BRIDGE_LINT_EMPTY` | warning | The application exposes no tools. The hint: "Add #[McpTool] to an action. mcp-bridge:tools --refused lists what was found and why it is not exposed." |
| `MCP_BRIDGE_LINT_NO_RULES` | warning | A model `create` or `update` tool with no `#[McpInput]` rules, so the database is its only validator. |
| `MCP_BRIDGE_LINT_HIDDEN_DOC` | note | A documented parameter the agent never sees. |
| `MCP_BRIDGE_LINT_TIMEOUT_ADVISORY` | note | A `#[McpTimeout]` on a source that runs in-process, where it tells the agent what to expect and interrupts nothing. |
| `MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE` | warning | An attribute on a source that does not read it: `#[McpQueue]` off a job, `#[McpInput]` or a query attribute on a job, action or command, `#[McpTool(operations:)]` off a model, `#[McpTool(description:)]` on a model, or a `#[McpParam]` naming a field the tool does not have. It changes nothing where it sits. |
| `MCP_BRIDGE_NOT_EXPOSED`, `MCP_BRIDGE_IGNORED` | note | Deliberate choices, shown only with `--all`. |

## Exceptions

| Class | When |
| --- | --- |
| `Exceptions\JobNoLongerAuthorized` | A queued job's actor was deleted or lost the ability before the worker ran it. The job fails rather than running. |
| `Exceptions\InvalidQueryInput` | `ApiQuery`, running without Spatie, was given a filter, sort, include or field that is not allowed. |
