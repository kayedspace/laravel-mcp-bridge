# Resources

Resources are reads of the application itself rather than tools. An agent that can see the whole API surface, including the endpoints that are not tools, can tell "this application cannot do that" from "that is not exposed to me", and ask for the right thing instead of trying tool after tool.

They are the one source that produces MCP resources instead of MCP tools.

## What it exposes

Four fixed resources and one template, selected by name in `sources.resource.expose`:

| Name | URI | Contents | On by default |
| --- | --- | --- | --- |
| `routes` | `app://routes` | Every route that passes the discovery filter, with its method, URI, route name and the tool it became (or `null`). Never the controller behind it: an application's class layout is reconnaissance an agent has no use for. | Yes |
| `schema` | `app://schema` | Every tool on the current server: name, source, module, target, description, safety, scopes, whether it confirms, and the visible inputs with type, required flag, description, enum, default and examples. | Yes |
| `schedule` | `app://schedule` | The scheduler's events: description, cron expression, timezone, `without_overlapping`, `on_one_server`. | No |
| `config` | `app://config` | A fixed summary: name, environment, locale, fallback locale, timezone, currency (if Cashier configures one), Laravel and PHP versions. | No |
| `records` | `app://records/{tool}/{id}` | A resource template: one record, read through the `get` model tool named in the URI. Served on a server that carries such a tool, and only there. | No |

`routes` and `schema` only describe what an agent can already reach, so they are on by default. `schedule` is a map of background behaviour and `config` says how the application is configured, so both are opt-in. `config` is a curated list, never a dump of `config()`: that holds credentials, and there is no safe way to filter it, only a way to name in advance what will be said.

Hidden fields (those in `security.fill_from_auth`, or hidden with `#[McpParam]`) are left out of `schema`, because describing them would only invite an attempt.

## What it needs to be opted in

Two things:

1. `sources.resource.enabled` is `true` (the default), and the names you want are in `sources.resource.expose`.
2. For the four fixed resources, the server lists `resource` in its `sources`. The shipped `default` server does; `ops` does not.

The `records` template is the exception to the second rule. `GeneratedServer` adds it to any server that carries a model `get` tool, whatever that server's `sources` says, because the template is only useful beside the tool it reads through. With the shipped configuration model tools are on `ops`, so `records` is served there and not on `default`.

## Reading one record

A client that attaches resources to a conversation wants a record it got an id for from a list as something it can hold, rather than a tool it has to call again. With `records` exposed, `app://records/model_get_course/12` reads course 12 through the `model_get_course` tool.

Both checks apply, in this order:

1. **The resource scopes.** `sources.resource.scopes` is checked first, as for every resource, when listing and when reading.
2. **The tool's own gate.** The read then runs the named tool through the same runner a tool call uses, so the tool's scope and ability, the model's policy, the shaping and the audit entry are all the tool's own. An actor who could not call `model_get_course` cannot read through it either.

A URI that names a tool that is not a model `get` tool on this server, or an id that does not exist, answers with an error that says so.

## Who may read them

Resources take scopes from `sources.resource.scopes`, one list for all five. A caller sees and reads a resource only when its token holds every scope listed:

- `['*']`, the shipped value, lets any authenticated caller read them.
- `['ops:read']` limits them to tokens holding that scope.
- `[]` grants nobody.

The check runs when resources are listed and again when one is read, for the same reason tools are checked twice: a client that listed a minute ago, or one that guesses a uri, reaches the read without listing at all. They describe an application's shape rather than its records, but a narrowly scoped token handed every route and every model's columns is still reconnaissance nobody granted. There is no ability check on the resources themselves (the `records` template adds the tool's own, above), and the server's middleware still runs first. The rules behind this are on the [security page](/guide/security).

## Example

Offer the schedule as well:

```php
// config/mcp-bridge.php
'sources' => [
    'resource' => [
        'enabled' => true,
        'expose' => ['routes', 'schema', 'schedule'],
    ],
],
```

A client reads `app://schedule` the way it reads any MCP resource. An agent asked "why did this number change overnight?" can then see which commands and jobs run on a timer.

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `sources.resource.enabled` | `true` | Switch resources off for every server. Call time. |
| `sources.resource.expose` | `['routes', 'schema']` | Which of `routes`, `schema`, `schedule`, `config`, `records` are served. `*` is all five, and an empty list is none. Call time. |
| `sources.resource.scopes` | `['*']` | Scopes a token must hold to list or read the resources. `*` is any authenticated caller, and an empty list grants nobody. Call time. |
| `servers.<key>.sources` | `default` lists `resource` | Whether that server offers the four fixed resources. `records` follows the model `get` tools instead. Call time. |
