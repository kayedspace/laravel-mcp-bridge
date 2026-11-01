# Documentation packages

If your API is already documented, that documentation is a schema source. The package reads [`dedoc/scramble`](https://scramble.dedoc.co) attributes and [Scribe](https://scribe.knuckles.wtf) docblock annotations as declared input, so adopting either package after the other needs nothing rewritten.

Neither is a dependency. Scramble's classes are consumed by fully qualified name and never redeclared, and nothing loads unless Scramble is installed. Scribe's annotations are plain text in your docblocks, so nothing needs to be installed to read them.

## Scramble

Scramble 0.13.25 or later, the first release with `#[IgnoreParam]`. Composer refuses an older one beside this package. These attributes are read:

| Scramble | Effect on the tool |
| --- | --- |
| `#[QueryParameter]`, `#[BodyParameter]`, `#[PathParameter]`, `#[HeaderParameter]` | A typed, described input. A bracketed name (`filter[author]`, `meta[source]`) becomes a nested input; an empty pair of brackets (`tags[]`) becomes a list. |
| `#[Endpoint(title:, description:)]` | The tool's title and description, when `#[McpTool]` gives none. |
| `#[Group]` | The tool's group. |
| `#[IgnoreParam]` | Hides that parameter from the agent. |
| `#[ExcludeRouteFromDocs]`, `#[ExcludeAllRoutesFromDocs]` | Keeps the route out of the tool list too. |

```php
#[Group('Catalogue')]
class ScrambleController
{
    #[McpTool(name: 'list_documented')]
    #[Endpoint(title: 'Browse courses', description: 'Everything in the catalogue.')]
    #[QueryParameter(name: 'filter[author]', description: 'Only what this user wrote: pass their id.', type: 'string')]
    #[QueryParameter(name: 'per_page', description: 'Results per page.', type: 'integer', default: 15)]
    public function index(Request $request): JsonResponse { /* ... */ }
}
```

Headers are left out of the schema unless you opt in with `integrations.scramble.read_headers`. When on, a documented header becomes an input and is sent as a header on the sub-request, never overwriting the headers the executor sets itself (`Authorization`, `Content-Type`, `Accept`, `X-Request-Id`, `X-Mcp-Tool`, `X-Mcp-Client`).

### Typed output from inferred responses

Scramble infers what each route answers by reading the API Resource behind it. With `integrations.scramble.responses` on, that inference becomes the tool's output schema: each key of the record with the type, enum and format Scramble worked out. A `data` envelope is unwrapped and a collection is reduced to its member, which is what the shaper does to the response itself.

```php
'integrations' => [
    'scramble' => ['responses' => true],
],
```

A key Scramble could not type is left out rather than guessed, so an untyped `getKey()` is absent while a cast attribute is present with its type. Declared `#[McpOutput(fields: ...)]` narrows the typed record to the kept keys.

It is opt-in because it runs Scramble's generator over every documented route the first time a manifest compiles in a process. On a large application that is seconds, paid once per deploy with the manifest cached and once per process without it.

## When both describe the same field

Scramble's `#[QueryParameter]` and this package's `#[AllowFilter]` can both land on `filter[author]`. They answer different questions, so both are read:

| | Decides |
| --- | --- |
| `#[AllowFilter]`, and a Spatie chain | What the endpoint accepts: the type, the enum, the wire name, whether several values are allowed. |
| `#[QueryParameter]` | What the field means, in words. |

The query layer is the later of the two, so it wins on what the endpoint accepts, which is its job. It does not win on prose it generated rather than read: a `#[QueryParameter(description: ...)]` survives, and the template sentence this package would otherwise have written ("Keep only rows whose author contains this.") gives way to it.

A description on the query attribute itself is a different matter. Two developers describing one field is a real disagreement, and the later layer settles it:

```php
// The agent is told "Only what this user wrote: pass their id."
#[QueryParameter(name: 'filter[author]', description: 'Only what this user wrote: pass their id.')]
#[AllowFilter('author')]

// The agent is told "Whose work to show." Later layer, and it said something.
#[QueryParameter(name: 'filter[author]', description: 'Only what this user wrote: pass their id.')]
#[AllowFilter('author', description: 'Whose work to show.')]
```

`mcp-bridge:tools <tool> --explain` names the layers that touched each field, so you can see which one answered.

## Scribe

These annotations are read from the docblock:

```php
/**
 * @group Catalogue
 *
 * @queryParam per_page integer Results per page. Example: 15
 * @bodyParam  title    string  required The course title.
 * @urlParam   course   integer required The course id.
 */
```

`@queryParam`, `@bodyParam` and `@urlParam` become typed inputs with their description, example and required flag. A controller's `@group` is the group of every action in it. `@hideFromAPIDocumentation` keeps the route out of the tool list.

## Which one wins

The layers, from weakest to strongest, are rules, documentation, the allowed query, then this package's own attributes. In practice:

- **Descriptions and titles.** Your `#[McpTool(description: ...)]` wins over `#[Endpoint]`. Documentation fills in only where the package's own attributes say nothing.
- **Parameters.** A documented parameter overrides what was inferred from rules, and `#[McpParam]` overrides a documented one.
- **Hiding.** `#[IgnoreParam]` hides a field. `#[McpParam(hidden: true)]` does too.
- **Exclusion.** A route your documentation excludes is kept out of the tool list while `discovery.respect_docs_exclusions` is on (the default). Turn it off and the route stays a candidate.

Where the two disagree about one field, the MCP attribute wins for the agent and the documentation wins for the document. Then your REST docs and your agent schema tell two different stories about one parameter. `mcp-bridge:lint` warns about it as `MCP_BRIDGE_LINT_DIVERGENCE`, and notes a documented parameter the agent never sees as `MCP_BRIDGE_LINT_HIDDEN_DOC`. Which one is wrong is not the package's call.

## Switching a reader off

```php
'integrations' => [
    'scramble' => ['enabled' => true, 'read_headers' => false, 'responses' => false],
    'scribe' => ['enabled' => true],
],
```

Setting `enabled` to `false` stops that reader without touching the documentation.

## Feeding Scramble

The other direction is opt-in. A team that annotates for agents first and wants REST docs second should not write the same thing twice. Register the extractor once in a service provider:

```php
use KayedSpace\McpBridge\Integrations\ScrambleParameters;

public function boot(): void
{
    ScrambleParameters::register();
}
```

`#[AllowFilter]`, `#[AllowSort]`, `#[AllowInclude]`, `#[AllowFields]` and `#[Paginate]` then become real OpenAPI query parameters, with the same names and descriptions the agent sees. Nothing happens if Scramble is not installed, and a route with no query declaration is left alone. It is opt-in because feeding anything into a team's public API document without being asked would be a surprise.
