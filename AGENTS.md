# kayedspace/laravel-mcp-bridge

A Laravel package that turns what an application already declares (routes,
Artisan commands, jobs, actions, models) into MCP tools for AI agents, on top
of the official `laravel/mcp` package.

Read this before changing anything here. What you need to work safely is in
this file, in `tests/ArchTest.php`, and in the comments, which say why rather
than what.

## Quick commands

| Command | What it does |
| --- | --- |
| `composer test` | The whole gate: PHPStan at max, Pint in check mode, the suite. Same as CI |
| `composer analyse` | PHPStan at max level over `src` and `database` |
| `composer lint` | Pint, fixing. `composer lint:check` reports instead |
| `composer test:unit` | Pest alone, in random order |
| `composer serve` | The workbench app, with a live `/mcp` endpoint to point a client at |
| `composer build` | Rebuild the workbench database and assets |

Run `composer test` before you believe a change works. Between edits, run only
the affected test file.

## How this package is put together

A call goes through the same sequence whatever it came from, and each step is
one object:

1. **Discovery.** A `Source` finds candidates. There are eight: `route`,
   `command`, `job`, `action`, `model`, `resource`, `remote`, `openapi`. They
   never decide whether a call is allowed and never shape their own output.
2. **Schema.** Layers merge into one `InputSchema`, later layers winning per
   field: route parameters, validation rules, API documentation attributes,
   the allowed query of a list endpoint, this package's own attributes, then
   config. `JsonSchemaCompiler` turns the result into `Illuminate\JsonSchema`
   types as the very last step.
3. **Compilation.** `ManifestCompiler` names, authorises, limits and groups
   each candidate once, at build time, into a `ToolDefinition`.
4. **Running.** `ToolRunner` is the one path: authorize, execute through the
   source, shape, record. A connected client, `mcp-bridge:call` and the
   testing helpers all go through it, and none of them may skip it.

## Conventions this repository keeps

- `declare(strict_types=1)` everywhere. `tests/ArchTest.php` enforces this and
  several other rules; read it before wondering whether something is allowed.
- One name per concept, the technical one, renamed everywhere in one pass.
- Before writing a class: does a package do it, does the framework do it, does
  the platform do it, is it a few lines at the call site. Only then your own
  class, and say in a comment what gap it fills.
- Comments say **why**, never what. The diff already says what.
- Never shadow another package's vocabulary. Consume `dedoc/scramble` and
  `laravel/mcp` attributes by their fully qualified names; declare our own
  only for concepts nobody else declares.
- An attribute that nothing checks is worse than no attribute. If you add one,
  add the thing that reads it in the same change.
- Never test what Laravel or a dependency guarantees. Test this package's own
  logic.

## The rule that matters most

Every tool is something an agent can reach into an application with, so
exposure is opt-in and authority is explicit:

- Only HTTP routes follow the configured `mode`, because a route arrives
  wrapped in middleware, a guard and usually a policy, and a tool call
  inherits all of it.
- Every other source is opt-in per item in every mode, and must declare both
  `#[McpScope]` and `#[McpAbility]`. Model tools additionally need a policy
  with a method for the operation.
- Scopes and abilities are checked when listing **and** again when calling,
  because hiding a tool is not enforcement.
- A guard that cannot report token abilities fails closed.

If a change makes any of those weaker, it needs a reason in the commit
message. Weakening an exposure default is the one change in this package that
cannot be quiet.

## Attributes and configuration say the same things

Every per-tool knob that exists in configuration is also an attribute on the
code it concerns, and every attribute is read by something; the lint reports
one that is not (`MCP_BRIDGE_LINT_UNREAD_ATTRIBUTE`). Precedence is one rule:
an attribute beats a source-level or global default in configuration, and a
per-route entry under `routes` beats both, because it exists to change one
tool without touching its code. A group a server claims places a tool before
the source's server or the module's does.

Exempt, on purpose: remote and OpenAPI settings (no PHP code to carry an
attribute), `allow_denied`, `discovery.include`, `discovery.exclude` and
`sources.command.deny` (allowlists and escape hatches are not liftable from
the code they keep out), and `sources.model.operations`, which an attribute
can narrow and never widen.

## Local skills

- `.agents/skills/add-a-source/SKILL.md`: adding a new kind of tool source,
  which is the package's main extension point.
- `.agents/skills/package-release/SKILL.md`: cutting a release.
- `.agents/skills/documentation/SKILL.md`: the documentation site, including the
  checklist that keeps it from falling behind the code.

## What ships, and what does not

`.gitattributes` decides. Tests, CI, the workbench and the design documents
are `export-ignore`d, so a `composer require` downloads `src`, `config`,
`database` and `resources` only. If you add a development-only directory, add
it there too.
