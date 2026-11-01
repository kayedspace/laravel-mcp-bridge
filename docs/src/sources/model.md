# Models

Eloquent models become tools, one per operation per model: `model_list_courses`, `model_get_course`, `model_create_course`, `model_update_course`, `model_delete_course`.

The `model_` prefix comes from `naming.source_prefixes`. Model tools mirror the resource routes in front of the same model, so without it a model's `get_course` and the route's `get_course` would want one name. See [source prefixes](/concepts/naming#source-prefixes).

This is the source with the least to inherit and the most reach, so it is the most constrained. A model tool exists only when the model opts in, declares its authority, **and** a policy exists with a method for the operation. The policy decides which operations exist at all, rather than being asked afterwards whether one was allowed.

## What it exposes

Models found under `sources.model.paths` (default `['app/Models']`), for the operations in `sources.model.operations`. The default is `['list', 'get']`: read-only. `create`, `update` and `delete` are opt-in on top of a policy. The source is off by default.

## What it needs to be opted in

1. `#[McpTool]` on the model class. That is the opt-in. A description there has no effect on a model, and a name does not rename its tools: a model names one tool per operation, so its names are generated and prefixed, `model_list_courses` and so on, whatever `name` says.
2. `#[McpScope]` and `#[McpAbility]` on the model class.
3. A registered policy for the model, with a method for each operation you enable:

| Operation | Policy method |
| --- | --- |
| `list` | `viewAny` |
| `get` | `view` |
| `create` | `create` |
| `update` | `update` |
| `delete` | `delete` |

A model with no policy produces no tools at all, however fully it opted in. A policy that is silent about an operation produces no tool for that operation, and `mcp-bridge:tools --refused` says which method is missing: "a policy that does not answer for an operation did not mean to allow it".

## Example

```php
use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool]
#[McpScope('courses:write')]
#[McpAbility('manage-catalogue')]
class Course extends Model
{
    protected $fillable = ['title', 'status', 'price'];

    protected function casts(): array
    {
        return ['status' => CourseStatus::class, 'price' => 'decimal:2'];
    }
}
```

```php
// config/mcp-bridge.php
'sources' => [
    'model' => [
        'enabled' => true,
        'operations' => ['list', 'get', 'create', 'update'],
    ],
],
```

With a `CoursePolicy` that defines `viewAny`, `view`, `create` and `update`, this compiles to `model_list_courses`, `model_get_course`, `model_create_course` and `model_update_course`. There is no `model_delete_course`, because the policy is silent about `delete`.

### One scope for every operation

`#[McpScope]` and `#[McpAbility]` sit on the model class, so they cover every operation of that model. There are no per-operation scopes. In the example above, `courses:write` is needed for `model_list_courses` and `model_get_course` too, so a token issued with only `courses:read` cannot even list courses.

Where reading and writing need to be told apart, put the read scope on the model (`#[McpScope('courses:read')]`) and let the policy decide the writes: `create`, `update` and `delete` are asked of the actor on every call, and an operation the policy does not define produces no tool at all. When a token must be able to read and never write, enable only `list` and `get` in `sources.model.operations`, or expose the writes as routes or actions with a scope of their own.

## The schema comes from the model

| Operation | Input |
| --- | --- |
| `list` | Filters, sorts, includes and paging, through the same [query layer](/guide/list-endpoints) a route list uses. Every exposed attribute is filterable, and sortable together with the key and the timestamps. A typed or enum-cast column is matched exactly; an untyped one is a partial text match. Includes are the relations the model declares with `#[AllowInclude]`. Page size is at most `output.max_items`. |
| `get` | The model's key, required, and `include` when the model declares relations. |
| `delete` | The model's key, required. |
| `create` | The exposed attributes, typed from their casts. A backed enum cast becomes an enum of its values. |
| `update` | The key, plus the exposed attributes. |

The exposed attributes are the model's `$fillable` columns without a dot, less every column in `$hidden` and every column `security.redact` matches. The match is the one redaction uses: a column whose name contains a listed entry, ignoring case, so `api_token` goes with `token`.

Only mass-assignable attributes are exposed. A model that guards an attribute from mass assignment is saying it is not for arbitrary callers, and an agent is the most arbitrary caller there is. An attribute the schema did not offer is never written.

::: warning Hidden and secret columns are left out of filters, sorts and writes
The output never shows a hidden column, but a filter on it would still be a prefix oracle on its value, a sort would still order by it, and a write would still set it. Laravel's default `User` has `password` both fillable and hidden, so a `User` opted in offers no `filter[password]`, no sort by it and no write to it. A write to such a column is declared on purpose, through `#[McpInput]`, whose rules are taken as written.
:::

To describe a write with validation rules instead, name a FormRequest with `#[McpInput]` on the model; the rules then say what is required as well as what is allowed, and replace the attribute list for `create` and `update`:

```php
#[McpTool]
#[McpScope('courses:write')]
#[McpAbility('manage-catalogue')]
#[McpInput(CourseStoreRequest::class)]
class Course extends Model { /* ... */ }
```

A `$fillable` column named in `security.fill_from_auth` (default `user_id`, `created_by`) is hidden from the agent as on every source. On `create`, `ModelExecutor` writes the actor's id into it; on `update` it is left as it is, because the policy that allowed the update was asked "may update" and never "may reassign".

### Relations are opt-in

Nothing infers which relations are for agents, so a model tool loads none until the model says which. The same query attributes a controller carries for a route list work on the model class, and `#[AllowInclude]` is the one that reaches relations:

```php
#[McpTool]
#[McpScope('courses:write')]
#[McpAbility('manage-catalogue')]
#[AllowInclude(['courses', 'courses.lessons'])]
#[AllowInclude('coursesCount', type: 'count')]
#[AllowInclude('notes', ability: 'see-notes')]
class Programme extends Model
{
    protected $hidden = ['secrets'];
}
```

- **`list`** gains `include` and loads what is asked with `with()`, counts and aggregates included.
- **`get`** gains the same `include` and loads it onto the record with `load()`, after the policy has answered about that record, so nothing related is read for a record the actor may not see.
- **A relation in `$hidden` is out.** It is dropped from the offer whatever the attribute says, because `toArray()` would drop it on the way out and an input that costs a query and changes nothing is worse than none. Hidden is the model's word, and it is final.
- **A relation behind `ability` exists only for actors the Gate allows.** The Gate is where a condition lives in Laravel, so "practitioners see notes" is `Gate::define('see-notes', fn (User $user) => $user->isPractitioner())` and nothing else. For an actor it denies, the include is refused as not allowed and the message lists what they may have.
- **Depth and count are capped** by `query.max_include_depth` and `query.max_includes`, as for a route.

Declared `#[AllowFilter]`, `#[AllowSort]` and `#[Paginate]` on the model layer over what the schema inferred, so a filter can be given a type or a description without restating the rest.

## How it runs

`ModelExecutor` runs the operation against Eloquent as the actor, asking the policy first every time, and about the record wherever one exists: "may this user update course 7" is the question your own code would ask, and a model tool that asked anything weaker would be a way around it.

| Operation | Result |
| --- | --- |
| `list` | A paginated list, with the includes asked for on each row. |
| `get` | The record, with the includes asked for. A missing key is a not-found error. |
| `create` | The new record. |
| `update` | The updated record. |
| `delete` | `{ "deleted": true, "id": ... }`. |

No API Resource runs. A model tool answers with the record's `toArray()`, so `$hidden`, `$visible`, `$appends` and the casts apply to the record and to every relation loaded onto it, each related model keeping its own `$hidden`, and nothing else transforms it. This is the one place a tool's answer can differ from what a REST route returning a `JsonResource` would give; if a column must never reach an agent, hide it on the model or expose the route instead.

## Safety is inferred from the operation

The operation says exactly what the work is, which is the one case where safety can be inferred with confidence. `list` and `get` are read-only, `update` is idempotent, `delete` is destructive, and `create` is a plain write. `delete` therefore asks for [confirmation](/guide/security#destructive-calls-and-confirmation) by default.

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `sources.model.enabled` | `false` | Switch the source on. |
| `sources.model.operations` | `['list', 'get']` | Which operations become tools. An empty list is none. |
| `sources.model.require_policy` | `true` | Require a policy method per operation, and ask it per record. |
| `sources.model.paths` | `['app/Models']` | Where classes are scanned. |

::: danger Do not turn off `require_policy` casually
With it off, no policy method is required and none is asked at run time. The scope and the Gate ability are then the only checks between an agent and the table. The setting exists for models that are deliberately open to any holder of the scope, and exposing a model that way should be a decision someone reviews.
:::

The shipped `ops` server lists `model` among its sources, so model tools land on `/mcp/ops`, behind `auth:mcp-bridge`. Set `sources.model.server` to place them elsewhere; the full order is in [where a tool lands](/guide/servers-and-modules#where-a-tool-lands).
