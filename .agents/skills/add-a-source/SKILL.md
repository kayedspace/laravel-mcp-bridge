---
name: add-a-source
description: >
  Add a new kind of tool source to kayedspace/laravel-mcp-bridge: a new place
  tools are discovered from, such as a CQRS bus, a scheduler or an in-house
  framework. Use when asked to expose something that is not a route, command,
  job, action, model, app resource or remote server.
license: MIT
---

# Adding a tool source

A source finds things that could become tools and runs one when asked. It
never decides whether a call is allowed, never writes audit entries and never
shapes its own output. That is what keeps seven sources from becoming seven
security models, and it is the first thing to get right.

## Before writing anything

Read `src/Contracts/Source.php` and `src/Data/ToolCandidate.php`, then
`src/Sources/JobSource.php`, which is the smallest complete example. Each
contract's docblock says what it is for and what replacing it buys.

Then answer: does an existing source already cover this? A thing reachable
through a route is a route. A thing reachable through a job is a job. A new
source is for a new *kind* of entry point, not a new subject area.

## The four methods

```php
public function key(): string;                  // 'route', 'command', ...
public function discover(): iterable;           // ToolCandidate, sorted
public function schema(ToolCandidate $c): InputSchema;
public function validate(ToolCandidate $c): array;  // list<Refusal>
public function execute(ToolCall $call): SourceResult;
```

- `discover()` must be **deterministic**: sort by candidate id before
  returning, so an unchanged application always compiles to an identical
  manifest.
- `validate()` returns refusals rather than silently skipping. A candidate
  that is found and not exposed stays visible to `mcp-bridge:lint` with a
  reason.
- `execute()` returns a `SourceResult`, not a shaped result. Mapping a failure
  to something an agent can act on is the `ErrorMapper`'s job.

## Authority is not optional

Unless the source inherits HTTP middleware, it is opt-in per item in every
mode, and `AuthorityRequirement` will refuse a candidate that declares no
scope and no ability. Do not work around that. If the new source genuinely
inherits protection the way a route does, say so in the commit message,
because it is the exception and a reviewer has to be able to see that you
meant it.

Use `Sources\Concerns\RefusesCandidates` for the three checks every
class-based source shares: ignored, not opted in, not buildable from an
agent's arguments.

## The steps

1. `src/Sources/<Name>Source.php` implementing `Source`.
2. `src/Execution/<Name>Executor.php` implementing `ExecutesTools`, if running
   it is more than a method call.
3. A `Target` in `src/Data/` if none of `RouteTarget`, `CommandTarget`,
   `ClassTarget` or `RemoteTarget` describes where the work lives, and a case
   in `Targets::fromArray()`. The target must survive a round trip through a
   plain array: the manifest is a PHP file.
4. Register it in `McpBridgeServiceProvider`, inside the existing
   `afterResolving(SourceRegistry::class, ...)`.
5. A `sources.<key>` block in `config/mcp-bridge.php`, `enabled` false by
   default, and a `server` naming which endpoint it belongs on.
6. Tests in `tests/Feature/<Name>SourceTest.php`: discovery, the refusals,
   the schema, and execution. Copy the shape of `JobSourceTest`.
7. A line in the pull request saying what the new source exposes and what
   authority it needs, because that is the part a reviewer cannot infer from
   the diff.

## What usually goes wrong

- **Inferring safety from a name.** `delete_*` is not evidence. Only the model
  source infers safety, because there the operation is the work. Everywhere
  else the declaration decides.
- **Guessing at an input.** If a parameter cannot be built from JSON, refuse
  the candidate with the parameter named rather than exposing a tool that
  fails on its first call.
- **Running the thing to find out about it.** Discovery must not execute
  application code. Use reflection, or `Support\Ast` for static analysis.
