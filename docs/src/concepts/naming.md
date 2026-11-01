# Naming and grouping

An agent chooses a tool by its name and description, so names are compiled with care and a collision is reported, never resolved silently.

## How a name is chosen

First match wins:

1. The explicit name: `#[McpTool(name: 'publish_course')]`.
2. A name derived from the source.
3. For anything without a derivable name, the candidate id with separators turned into underscores.

Every name, explicit ones included, is lowered to snake case, stripped of anything outside `a-z`, `0-9` and `_`, and limited to 64 characters. `naming.style` then sets the casing of the result:

| `naming.style` | Example |
| --- | --- |
| `snake` (default) | `list_courses` |
| `camel` | `listCourses` |
| `kebab` | `list-courses` |

Snake case is what an MCP tool name conventionally looks like. The other two are for a server whose other tools already use them. The examples on this page use the default.

### Derivation by source

| Source | Rule | Example |
| --- | --- | --- |
| Route with a name | Drop `discovery.prefixes` and version segments (`v1`), take the last segment as the action, map it through `naming.verbs`, pluralise the subject for a collection. | `api.v1.courses.index` becomes `list_courses`; `api.v1.courses.show` becomes `get_course`. |
| Nested route | Parent segments read as singular, only the subject is pluralised. | `courses.lessons.index` becomes `list_course_lessons`. |
| Route, custom action | An action that is not in `naming.verbs` is its own verb. | `courses.publish` becomes `publish_course`. |
| Route without a name | HTTP verb plus the static URI segments. | `POST api/reports/run` becomes `create_reports_run`. |
| Command | Namespace and action, with the action mapped through the verbs. | `stats:recalculate` becomes `recalculate_stats`. |
| Job or action | The class name, minus a trailing `Job`, `Action` or `Command`. | `SendInvoiceJob` becomes `send_invoice`. |
| Model | The source prefix, the operation verb and the model name. | `model_list_courses`, `model_get_course`, `model_create_course`, `model_update_course`, `model_delete_course`. |
| Remote | `<prefix>_<remote tool name>`; the prefix defaults to the server key. | `billing_list_invoices`. |
| OpenAPI | `<prefix>_<operationId>`, snake-cased; the prefix defaults to the document key. An operation without an `operationId` is named from its method and path, as a route without a name is. | `listInvoices` in the `billing` document becomes `billing_list_invoices`. |

`naming.verbs` maps resource actions to verbs: `index` to `list`, `show` to `get`, `store` to `create`, `update` to `update` and `destroy` to `delete`. Rename them there, in one place. `naming.pattern` is `{verb}_{resource}` by default; set it to `{resource}_{verb}` to flip every name to `courses_list`.

## Source prefixes

`naming.source_prefixes` gives every derived name from one source a prefix, keyed by source. It ships as `['model' => 'model']`, so model tools are `model_list_courses` and `model_get_course`. Model tools mirror resource routes by design, so without the prefix the model's `get_course` and the route's `get_course` would want one name the moment the model source is turned on.

| Rule | Effect |
| --- | --- |
| Style | The prefix is joined to the name and the result takes `naming.style`, so it reads as one name: `model_get_course`, `modelGetCourse` or `model-get-course`. |
| Explicit names | A name given in `#[McpTool(name: ...)]` is never prefixed where the source takes it. A model names one tool per operation and takes no single name, so its tools stay prefixed. |
| Other sources | Only sources listed get a prefix. Route tools keep their names. Remote and OpenAPI tools carry their server or document key instead, as in the table above. |
| Switching it off | Set the source's entry to an empty string, or remove it. |

## Collisions

Sources compile in a fixed order: `route`, `command`, `job`, `action`, `model`, `resource`, `remote`, `openapi`, then third-party sources by key (`SourceRegistry::PRECEDENCE`). The first candidate to claim a name keeps it. A later candidate that wants the same name is refused with `MCP_BRIDGE_NAME_TAKEN`, naming the candidate that holds it, rather than shadowing it.

A collision costs that one tool, never the build. `mcp-bridge:cache` succeeds and every other tool is served, so a refused tool is easy to miss: `mcp-bridge:tools --refused` lists it with the fix in the message. `mcp-bridge:lint` reports a collision as the error `MCP_BRIDGE_LINT_COLLISION`, so CI catches it. Resolve it by giving one of them `#[McpTool(name: ...)]`.

## Groups

A group is a label on a tool. Declare one with [`#[McpGroup]`](/reference/attributes#mcpgroup), on a class or a method, repeatably:

```php
#[McpGroup('reports')]
class ReportController { /* ... */ }
```

When a tool declares none, the group falls back, in order, to:

1. the group your documentation already declares: Scramble's `#[Group]` or Scribe's controller `@group`;
2. the module the code lives in, when `modules.grouping` is `module` (the default);
3. for a remote tool, the remote server's key;
4. for an OpenAPI operation, the document key.

Groups are recorded on each tool definition and shown in the exported tool data. They also decide which server serves a tool: a group listed in a server's `groups` claims the tools that carry it, whatever source they came from, which is how one route moves to the `ops` server without moving every route. A server's `groups` of `['*']` claims nothing. The module fallback labels a tool without claiming a server. The full order is in [where a tool lands](/guide/servers-and-modules#where-a-tool-lands).
