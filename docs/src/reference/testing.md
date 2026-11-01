# Testing helpers

`KayedSpace\McpBridge\Testing\InteractsWithMcp` is a trait for your test case. Everything it does compiles fresh from your declarations, and calls go through `ToolRunner`, gate and audit included. For the reasoning and worked examples, see the [testing guide](/guide/testing).

```php
use KayedSpace\McpBridge\Testing\InteractsWithMcp;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithMcp;
}
```

Assertions return `static`, so they chain.

## Reading the surface

| Method | Returns | Description |
| --- | --- | --- |
| `mcpTools()` | `CompileResult` | The compiled surface: `definitions`, `refusals`, and `names()`. Compiled once per test. |
| `refreshMcpTools()` | `void` | Forget the compiled surface, for a test that changes configuration partway. |
| `mcpTool(string $name)` | `ToolDefinition` | One compiled tool. Fails the test, listing what exists, when there is none. |
| `mcpContract()` | `array` | What a client is handed: for each tool, sorted by name, its `description`, `inputSchema`, `outputSchema` and `annotations`. The subject for a Pest snapshot. Deliberately not the manifest. |

## Calling

| Method | Returns | Description |
| --- | --- | --- |
| `callMcpTool(string $name, array $arguments = [], ?Authenticatable $as = null, ?string $confirm = null, ?string $idempotencyKey = null)` | `ToolResult` | Call a tool as an agent would. `$as` defaults to `auth()->user()`; with neither, the test fails rather than guess. `$confirm` passes a confirmation handle and `$idempotencyKey` an [idempotency key](/concepts/output-and-errors#retries-and-idempotency-keys). A tool that declares a scope needs an actor holding a token: see [tools that declare a scope](/guide/testing#tools-that-declare-a-scope). |
| `confirmMcpTool(string $name, array $arguments = [], ?Authenticatable $as = null)` | `ToolResult` | Walk the confirmation handshake: ask once, then call again with the handle. If the first call did not ask for confirmation, returns that result without calling twice. |

`ToolResult` has `text`, `structured`, `isError`, `errorCode` and `meta`.

## Assertions

| Method | Fails when |
| --- | --- |
| `assertToolExists(string $name)` | The tool is not exposed. |
| `assertToolMissing(string $name)` | The tool is exposed. |
| `assertToolRefused(string $candidateId, ?string $code = null)` | The candidate was not found and refused. With `$code`, it was refused with a different code. This is different from never having been discovered. |
| `assertToolInputs(string $name, array $inputs)` | Any listed input path is absent from the tool's schema. |
| `assertQueryInputs(string $name, array $filters = [], array $sorts = [], array $includes = [])` | The list tool does not offer a filter, or does not allow a sort or include value. |
| `assertToolReadOnly(string $name)` | The tool is not read-only. |
| `assertToolNeedsConfirmation(string $name)` | The tool runs without asking. |
| `assertToolRequiresScopes(string $name, array $scopes)` | The tool does not require every listed scope. |

## Notes

- A call made by a helper identifies itself as client `tests` in the audit trail and the `ToolCalled` event.
- Define your routes before calling a helper; they are discovered live. In a package test that registers routes in the test body, call `refreshMcpTools()` after.
- Because `mcpTools()` compiles the whole surface, a failing discovery throws on first use, which is the right time to find out.
