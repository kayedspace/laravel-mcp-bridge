# Troubleshooting

Start with the same two commands every time. They answer most questions outright.

```bash
php artisan mcp-bridge:doctor           # configuration, guards, limiters, manifest, tables
php artisan mcp-bridge:tools --refused  # what was found and not exposed, with the reason
```

## A tool does not appear

Work through these in order.

**1. The manifest is stale.** A new route is missing, a removed route still answers, or a changed description or scope is not reflected: a cached [manifest](/concepts/how-it-works#glossary) predates the change. `mcp-bridge:tools` reads what the server reads, so if the tool is missing there, rebuild with `mcp-bridge:cache` or clear with `mcp-bridge:clear`, as [keeping the manifest current](/guide/operations#keeping-the-manifest-current) describes.

**2. It was found and refused.** `--refused` prints the [candidate](/concepts/how-it-works#glossary) id, a code and the reason:

| Code | Meaning | Fix |
| --- | --- | --- |
| `MCP_BRIDGE_NOT_EXPOSED` | Not opted in. "Add #[McpTool] to opt it in." | Add `#[McpTool]`, or change `mode` for routes. |
| `MCP_BRIDGE_IGNORED` | It carries `#[McpIgnore]`, or your documentation excludes the route. | Remove it, or set `discovery.respect_docs_exclusions` to `false`. |
| `MCP_BRIDGE_SOURCE_REFUSED` | The [source](/concepts/how-it-works#glossary) or the [authority](/concepts/how-it-works#glossary) rule said no. The message says why. | See below. |
| `MCP_BRIDGE_INVALID_RATE_LIMIT` | Its rate limit cannot be read, so it is not served rather than served with no limit. The message names the value and where it was set. | Write `N/second`, `N/minute`, `N/hour` or a window such as `N/5minutes`. |

Typical `MCP_BRIDGE_SOURCE_REFUSED` messages:

- "needs both #[McpScope] and #[McpAbility] because it is not an HTTP route": add both. See the [security model](/guide/security).
- "The command [x] is on the deny list": it is on the built-in floor or `sources.command.deny`. Use `allow_denied` only if you are certain.
- "outside the namespaces this application allows for tools": extend `sources.command.namespaces`.
- "The model [x] has no registered policy": register one with `Gate::policy()` or the policy auto-discovery convention.
- "needs [CoursePolicy::delete()]; a policy that does not answer for an operation did not mean to allow it": add the method, or drop `delete` from `sources.model.operations`.
- "cannot be built from an agent's arguments: rows: it has no type": type the constructor or method parameter, or use `#[McpIgnore]`.

**3. It was never found.** Check, for a route: it is a controller action (not a closure); its method is in `discovery.methods`; its URI starts with an entry of `discovery.prefixes`; it matches `discovery.include` if set; it does not match `discovery.exclude` (note the shipped excludes). For a command, job, action or model: `sources.<key>.enabled` is `true`, and the class is under `paths`. For a command, it must extend `Illuminate\Console\Command`.

**4. The tool is on another server.** With the shipped configuration only route tools are on `/mcp`; command, job, action, model, remote and OpenAPI tools are on `/mcp/ops`, behind `auth:mcp-bridge`. A group or a per-module server can move a tool too. The `Server` column of `mcp-bridge:tools` says where each one is, and [where a tool lands](/guide/servers-and-modules#where-a-tool-lands) says why.

**5. It is hidden from this actor.** A tool is not listed to an actor who lacks a [scope](/concepts/how-it-works#glossary) or ability it declares. Try `mcp-bridge:tools` for what exists, then check the token's scopes with `mcp-bridge:tokens`, and call it with those scopes: `mcp-bridge:call <tool> --scopes=courses:read`.

**6. It is switched off.** `mcp-bridge:kill --list`.

**7. The list was replaced by search.** Past `tool_search_threshold` tools (default 40) a client sees `search_tools` and `execute_tools` instead of the flat list. Raise the threshold, set it to `0`, or search through the tool.

**8. Another tool holds its name.** A collision refuses the later candidate and builds everything else, so `mcp-bridge:cache` succeeds. `mcp-bridge:tools --refused` shows `MCP_BRIDGE_NAME_TAKEN` with the candidate that holds the name. Give one `#[McpTool(name: '...')]`. See [collisions](/concepts/naming#collisions).

## The client cannot connect

| Symptom | Likely cause |
| --- | --- |
| `401` on every request | The server's `middleware` guard does not recognise the token. Both shipped servers use `auth:mcp-bridge`, which reads package tokens from `mcp-bridge:token` (prefix `mcpb_`) and nothing else. A Sanctum token needs a server switched to `auth:sanctum`, and a package token sent to one is refused. See [issuing a token](/guide/getting-started#issue-a-token-and-connect). |
| `404` on the endpoint | `enabled` is `false`, the server has no `path`, or the path does not match. `doctor` reports a server with no registered route. |
| The endpoint answers, but with another server's tools | Your own `laravel/mcp` server is registered at the same path and replaced the package's. `doctor` names the server holding the path. Give one of them another path. |
| `500` on the first request | A guard or rate limiter named in the middleware is not defined. A server switched to `auth:sanctum` needs Sanctum installed (`php artisan install:api`). `doctor` names the missing one. |
| Connects, lists nothing | Nothing is exposed (`mcp-bridge:tools --refused` says why), the tools are on the other server, a cached manifest is stale, or the actor's token lacks every scope. |
| Every stdio call is refused as unauthenticated | A subprocess carries no token. Set `security.local_actor` to the user those calls run as; the refusal names the key, and `doctor` reports it when `local` is on. |
| Wrong URL in the printed setup | `APP_URL` is wrong. |

## A call is refused

The agent reads the message. The code is in `mcp-bridge:call`, the audit trail and the `ToolCalled` event.

| Code | Message starts | Meaning and fix |
| --- | --- | --- |
| `MCP_BRIDGE_SCOPE` | "This tool needs the scope [x]." | The package token, or the Sanctum token, lacks the scope. Issue one with it. |
| `MCP_BRIDGE_SCOPE` | "... the current guard cannot report token abilities." | The user carries no package token and has no `tokenCan()`. Add `HasMcpTokens` and connect with a package token, or use a guard whose user answers `tokenCan()`. This is the fail-closed rule. |
| `MCP_BRIDGE_SCOPE` | "... the caller holds no token." | The user has `HasMcpTokens` but arrived without a package token: a session or a factory. Connect with a token issued by `mcp-bridge:token`. In a test, give the actor a token: see [tools that declare a scope](/guide/testing#tools-that-declare-a-scope). |
| `MCP_BRIDGE_FORBIDDEN` | "Not allowed: this tool requires the [x] ability." | The Gate denied the ability for this user. |
| `MCP_BRIDGE_RATE_LIMITED` | "Retry after n seconds." | Per-tool limit. Raise `#[McpRateLimit]` or `sources.<key>.rate_limit`. |
| `MCP_BRIDGE_INVALID_RATE_LIMIT` | "has a rate limit that cannot be read." | The manifest was built before the compiler refused such a limit. Run `mcp-bridge:lint`, fix the value it names, then `mcp-bridge:cache`. |
| `MCP_BRIDGE_DISABLED` | "is switched off." | The kill switch. `mcp-bridge:kill --revive`. |
| `MCP_BRIDGE_CONFIRMATION_REQUIRED` | The preview text. | Not a failure. Call again with the `confirm` handle. |
| `MCP_BRIDGE_CONFIRMATION_EXPIRED` | "That confirmation is no longer valid ..." | Used, expired, forged or arguments changed. Call again without the handle for a fresh preview. |
| `MCP_BRIDGE_VALIDATION` | Field-by-field messages. | The route's rules rejected the input. Check the schema with `mcp-bridge:export`. |
| `MCP_BRIDGE_SERVER_ERROR` | Names a class in `execution.middleware`. | The class does not exist, so every call stops. Fix the configuration; `mcp-bridge:doctor` reports it under "Call middleware". |
| `MCP_BRIDGE_SERVER_ERROR` | "The application failed to handle the call." | An exception, from a route, a source, a call middleware or the shaping. It is in your exception handler's log. See [errors](/concepts/output-and-errors#errors). |

A confirmation that never succeeds on retry usually means the arguments differ between the two calls, the actor differs, or the cache cannot promise single use (a failed `Cache::add` fails closed). Check the cache connection.

## The answer looks wrong

- **Truncated.** The text says "The list was trimmed". Lower the result with filters, or raise `output.max_items` or `output.token_budget`.
- **Missing keys.** `security.redact` removes any key that *contains* a listed word, so `password_hint` goes with `password`. `#[McpOutput(fields: [...])]` keeps only the keys it names. `output.drop_nulls` removes nulls.
- **"The route did not return JSON."** The route answered HTML. An `auth` middleware redirecting to a login page is the usual cause: make sure the actor is handed to the guard your route uses (`security.guards`).
- **A redirect.** Reported with where it went. Same cause.
- **A job reports `queued` but nothing happens.** There is no worker for `sources.job.queue` (`mcp-bridge` by default).
- **A job fails with "can no longer [ability]".** `JobNoLongerAuthorized`: the actor lost the ability or was deleted between dispatch and run. This is working as intended.
- **A command fails with a non-zero exit.** The result carries the exit code and the last `output_tail_lines` lines. A command that prompts fails because interaction is off; `mcp-bridge:lint` reports it as `MCP_BRIDGE_LINT_INTERACTIVE`.

## Lint messages

Every finding `mcp-bridge:lint` reports, with its level and hint, is in the [lint findings table](/reference/error-codes#lint-findings). The command exits non-zero only when an error is present.

## Still stuck

Run the tool as the agent would, with the real pipeline, and read the full output:

```bash
php artisan mcp-bridge:call <tool> --json='{"id": 1}' --as=user@example.com --scopes=courses:read
```

Leave `--scopes` out to call with every scope, or pass the scopes the agent's token holds to see exactly what it sees.

Then open an [issue](https://github.com/kayedspace/laravel-mcp-bridge/issues) with the output of `mcp-bridge:doctor` and the relevant line of `mcp-bridge:tools --refused`.
