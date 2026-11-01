---
name: mcp-bridge
description: >
  Expose a Laravel application's existing routes, Artisan commands, jobs,
  actions and models to AI agents as MCP tools with kayedspace/laravel-mcp-bridge.
  Use when asked to make an application agent-accessible, to add or remove a
  tool, to open a model's relations to agents, to debug why a tool is missing
  or refused, or to connect a client. Operating a running server (deploy,
  incidents, tokens, the kill switch) is the mcp-bridge-operations skill.
license: MIT
metadata:
  author: Ali Youssef Kayed
---

# laravel-mcp-bridge

This package turns what the application already declares into MCP tools. The
schema comes from FormRequest rules, route model binding, query builder
chains and existing API documentation attributes, so the work is deciding
**what to expose**, not writing schemas.

## Primary goal

Expose the smallest useful surface, and let the application's own
middleware, policies and validation keep deciding. Never write a wrapper
controller, a duplicate schema, or a hand-built `Tool` class.

## Workflow

### 1. See what is there before changing anything

```bash
php artisan mcp-bridge:tools              # what is exposed, and from which source
php artisan mcp-bridge:tools --refused    # what was found and not exposed, with the reason
php artisan mcp-bridge:lint               # what will not work as declared
```

`--refused` answers most "why is my tool missing" questions outright. Read it
before changing code.

### 2. Expose a route

Put `#[McpTool]` on the controller action. Nothing else is required.

```php
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'List published courses with optional title search.')]
public function index(CourseIndexRequest $request) { /* unchanged */ }
```

The description is not decoration: an agent picks a tool and fills its
arguments from that text. Write what it does and when to use it.

Locally the manifest compiles on demand. Where one is cached (production, or
after `mcp-bridge:cache` or `optimize` was run), rebuild it:

```bash
php artisan mcp-bridge:cache
```

`php artisan optimize` writes it too, and `optimize:clear` removes it.

### 3. Expose anything that is not a route

A command, job, action or model has no middleware to inherit, so it needs
authority of its own or it is refused at compile time:

```php
#[McpTool(description: 'Recalculate course statistics for a date range.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class RecalculateStats extends Command { /* unchanged */ }
```

Enable the source in `config/mcp-bridge.php` (`sources.command.enabled`), and
a model additionally needs a policy with a method for each operation.

Model tools are named with the `model_` prefix (`model_list_courses`,
`model_get_course`), so they sit beside the routes for the same model. They
filter, sort and write only `$fillable` columns that are neither `$hidden`
nor matched by `security.redact`; declare a write to such a column through
`#[McpInput]`.

A name collision does not fail the build: the later candidate is refused with
`MCP_BRIDGE_NAME_TAKEN`, and `mcp-bridge:tools --refused` shows it. Give one
of them `#[McpTool(name: ...)]`.

### 4. Open a model's relations

A model tool loads no relation until the model says which. `#[AllowInclude]`
on the model class gives `list` and `get` an `include` input; `list` loads it
with `with()`, `get` with `load()` after the policy answered about the record:

```php
#[AllowInclude(['lessons', 'lessons.author'])]
#[AllowInclude('lessonsCount', type: 'count')]
#[AllowInclude('notes', ability: 'see-notes')]
class Course extends Model
{
    protected $hidden = ['secrets'];
}
```

A relation in `$hidden` is never offered, whatever is declared. `ability`
names a Gate ability without which the include does not exist for that
actor; the Gate is where a condition such as "practitioners see notes"
lives. `#[AllowFilter]`, `#[AllowSort]` and `#[Paginate]` on the model
refine what was inferred from the columns.

### 5. Check what the agent actually gets

```bash
php artisan mcp-bridge:call list_courses --arg=per_page=2
php artisan mcp-bridge:call list_courses --arg=per_page=2 --scopes=courses:read
```

This goes through the real pipeline: the same authorization, the same
execution, the same shaping a connected client gets. A destructive tool asks
for confirmation here too. An argument the schema does not declare is refused
with the names it accepts, for `call` and for a client alike.

### 6. Connect a client

```bash
php artisan mcp-bridge:token you@example.com --name=Claude --scopes=courses:read
php artisan mcp-bridge:connect claude --token=mcpb_... --apply
```

The first prints a token once. The second connects Claude Code on this
machine, or writes `.cursor/mcp.json` or `.vscode/mcp.json` for `cursor` and
`vscode`; without `--apply` it prints the configuration to paste. Both
shipped servers accept package tokens; Sanctum is a configured switch. The
user model needs the `HasMcpTokens` trait. A signed-in user issues their own
tokens on the panel at `/mcp-bridge`, or on the Filament page when the
plugin is registered.

## Rules

- **Describe every tool.** A tool with no description gets a generated one,
  and `mcp-bridge:lint` warns about it. The agent reads that text to decide.
- **Refine, do not duplicate.** Use `#[McpParam]` to describe, retype, hide or
  add one field. Do not write a second schema beside the FormRequest.
- **A destructive tool must say so.** `#[IsDestructive]` from `laravel/mcp`,
  or `#[McpConfirm('...')]` for the message the user sees. Confirmation is a
  two-step handshake: the first call returns a preview and a handle.
- **Hide what the application fills.** `user_id` and friends belong in
  `security.fill_from_auth`, not in the agent's hands.
- **Rebuild a cached manifest after changing a declaration.** Without
  `bootstrap/cache/mcp-bridge.php` the manifest compiles on demand; with one,
  an attribute nobody compiled does nothing until `mcp-bridge:cache` runs
  again.
- **Gate access to MCP as a whole with `accessMcp`.** When only some accounts
  may use the servers, such as subscribers, define the `accessMcp` Gate; it
  receives the server key and covers every tool. Do not add a check to each
  route for it.
- **Run `mcp-bridge:lint` in CI.** It exits non-zero on anything that will not
  work as declared, including a command that would stop to ask a question.

## Examples

**A list endpoint becomes a filterable tool.** An endpoint already using
`spatie/laravel-query-builder` needs no attributes at all: the allowed
filters, sorts, includes and sparse fieldsets are read from the chain and
become typed inputs. Add `#[AllowFilter('status', enum: CourseStatus::class)]`
only to enrich one filter with something the chain cannot say.

**A write endpoint keeps its validation.** `#[McpTool]` on `store()` exposes
the FormRequest's rules as the schema, and the call runs through the real
route, so the same rules reject the same input. Nothing is validated twice
and nothing is validated in two places.

**Testing it.** Use the shipped trait rather than calling tools by hand:

```php
use KayedSpace\McpBridge\Testing\InteractsWithMcp;

$this->assertToolExists('list_courses')
    ->assertToolReadOnly('list_courses')
    ->assertQueryInputs('list_courses', filters: ['title', 'status']);
```

## Anti-patterns

- **Do not write a `Tool` class by hand** when a route or command already does
  the work. That is the duplication this package exists to remove.
- **Do not set `mode` to `opt-out` to save typing.** It exposes every matching
  route, including ones nobody reviewed. It is a deliberate posture for an
  application whose entire API is meant to be agent-reachable.
- **Do not turn off `require_scope_for_non_http`.** It is the only thing
  standing between an agent and an unprotected command.
- **Do not expose a command that prompts.** An agent cannot answer a question;
  `--no-interaction` is always set, so the command fails. Add an option, or
  mark it `#[McpIgnore]`.
- **Do not pass secrets as tool arguments.** They are written to the audit
  trail, redacted only by key name.
- **Do not reach a relation by widening `$visible` or writing an accessor.**
  Declare it with `#[AllowInclude]`, and gate it with `ability` when only
  some actors may have it.
