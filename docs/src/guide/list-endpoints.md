# List endpoints

A list endpoint is where a typed schema pays off most. Allowed filters, sorts, includes and sparse fieldsets become structured inputs, so an agent sends `filter[status]=published` and `sort=-created_at` instead of guessing at a free-text query.

The allowed query can come from three places. They layer rather than replace each other, in this order, with later layers winning per name: the fluent chain, a shared definition, then attributes.

## 1. An existing Spatie chain

If the endpoint already uses [`spatie/laravel-query-builder`](https://spatie.be/docs/laravel-query-builder), you need no attributes at all:

```php
#[McpTool(name: 'list_catalog_chained', description: 'Search the catalogue, declared fluently.')]
public function chained(): JsonResponse
{
    $courses = QueryBuilder::for(Course::class)
        ->allowedFilters([
            'title',
            AllowedFilter::exact('status'),
            AllowedFilter::exact('author', 'user_id'),
            AllowedFilter::scope('starts_after'),
            AllowedFilter::trashed(),
        ])
        ->allowedSorts(['title', AllowedSort::field('created_at')])
        ->allowedIncludes(['lessons', AllowedInclude::count('lessonsCount')])
        ->allowedFields('id', 'title', 'lessons.title')
        ->defaultSort('-created_at')
        ->paginate(15);

    return new JsonResponse(['data' => $courses->items()]);
}
```

The chain is read by **static analysis**, never execution: the method is parsed, not called, because calling a controller to find out what it allows would hit the database. Only literal arguments are read. A name built from a constant, a variable or a `match()` is skipped rather than guessed; declare those with attributes. Switch this reader off with `query.read_chains`.

Add one attribute to enrich one filter with something the chain cannot say, and the rest of the chain stays as it is:

```php
#[AllowFilter('status', enum: CourseStatus::class, description: 'Only courses in this state.')]
```

## 2. Query attributes

With no chain, declare the whole thing in attributes. Put `#[QueryModel]` on the action so the package knows which model is queried, and the rest alongside it:

```php
use KayedSpace\McpBridge\Attributes\AllowFields;
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowInclude;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Attributes\Paginate;
use KayedSpace\McpBridge\Attributes\QueryModel;

#[McpTool(name: 'list_catalog', description: 'Search the course catalogue.')]
#[QueryModel(Course::class)]
#[AllowFilter('title', description: 'Search in course title')]
#[AllowFilter('status', type: 'exact')]
#[AllowFilter('author', type: 'exact', column: 'user_id')]
#[AllowFilter('starts_after', type: 'scope')]
#[AllowFilter('trashed', type: 'trashed')]
#[AllowSort('title')]
#[AllowSort('created_at', default: 'desc')]
#[AllowInclude(['lessons', 'instructor'])]
#[AllowInclude('lessonsCount', type: 'count')]
#[AllowFields(['id', 'title', 'status', 'price'])]
#[Paginate(perPage: 15, max: 50)]
public function declared(Request $request): JsonResponse { /* ... */ }
```

The attributes can sit on the method, on the controller class or on the model. They are read in that reverse order, so the model carries defaults every endpoint for it shares, the class carries the controller's, and the method has the last word per name.

| Attribute | What it declares |
| --- | --- |
| `#[AllowFilter]` | One filter: `name`, `type` (`partial` by default; `exact`, `beginsWith`, `endsWith`, `scope`, `callback`, `custom`, `trashed`, `belongsTo`, `operator`), `column` for an alias, `enum`, `description`, `example`, `multiple`. |
| `#[AllowSort]` | One sort: `name`, `default` (`asc` or `desc`), `column`, `using`, `description`. |
| `#[AllowInclude]` | One or several includes: `names`, `type` (`relation` by default; `count`, `exists`, `min`, `max`, `sum`, `avg`), `relation`, `column`, `description`, and `ability`, a Gate ability without which the include does not exist for the actor. On a model class it also opens the relation to the model's own tools, see [relations are opt-in](/sources/model#relations-are-opt-in). |
| `#[AllowFields]` | Sparse fieldsets: `fields`, and `resource` when it is not the base table. |
| `#[Paginate]` | `perPage`, `max`, `type` (`length-aware`, `simple`, `cursor`), `fixed`. |
| `#[QueryModel]` | The queried model, and an optional `resource`. |

The full parameter lists are in the [attribute reference](/reference/attributes).

The attribute names deliberately differ from Spatie's classes. Spatie ships no attributes, so there is nothing to consume, and a future attribute of theirs called `AllowedFilter` must not collide with one here.

### Applying the declaration, so it cannot drift

Declaring the query for the agent does not by itself change what the endpoint does. To build the query from the same declaration the schema was built from:

```php
use KayedSpace\McpBridge\Query\ApiQuery;

#[McpTool(name: 'list_catalog_applied', description: 'Search the catalogue through ApiQuery.')]
#[QueryModel(Course::class)]
#[AllowFilter('title')]
#[AllowSort('title')]
#[Paginate(perPage: 5, max: 20)]
public function applied(): JsonResponse
{
    return new JsonResponse(['data' => ApiQuery::fromAttributes()->paginate()->items()]);
}
```

With Spatie installed, `ApiQuery::fromAttributes()` hands the declaration to Spatie and returns its `QueryBuilder`. Without it, it applies the same declaration to an Eloquent builder using the identical query-string format, so a team can adopt Spatie later without touching either the attributes or the agent-facing schema. Pass a model, `ApiQuery::fromAttributes(Course::class)`, when the endpoint queries something other than `#[QueryModel]`. A team with fully custom query logic does not call it, and the attributes then describe the inputs for the agent and change nothing else.

## 3. A shared definition

When several endpoints share a list, declare it once as a public static `mcpQuery()` method on the controller, with no required parameters, returning a `QueryDefinition`:

```php
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Query\QueryDefinition;

class SharedQueryController
{
    public static function mcpQuery(): QueryDefinition
    {
        return new QueryDefinition(
            model: Course::class,
            filters: [new AllowFilter('title'), new AllowFilter('status', type: 'exact')],
            sorts: [new AllowSort('created_at', default: 'desc')],
            origin: 'definition',
        );
    }
}
```

## What the agent gets

| Declaration | Input |
| --- | --- |
| A filter | `filter.<name>`, sent as `filter[name]=value`. Typed from the model's casts, with the enum's values when it is a backed enum or `enum:` is given. A `multiple` filter takes a list. `trashed` takes `with` or `only`. |
| Sorts | `sort`, a delimited list from the allowed values, ascending and descending (`-field`). The default sort is described. |
| Includes | `include`, a delimited list. |
| Fields | `fields[<resource>]`. |
| Paging | `page` and `per_page` (at most `min(max, output.max_items)`); `page` only when `fixed`; `cursor` when the type is `cursor`. |

A filter on a cast attribute is typed from the cast, and one on a backed enum gets the enum's values, so an agent sends `published` rather than guessing. Reading real column types would need a live connection at compile time, which a build box may not have, so casts and enums are what is used.

If the agent sends something not allowed, the message that names the allowed values, Spatie's own or this package's, comes back as `MCP_BRIDGE_BAD_REQUEST`.

### Caps

An agent must not be able to load a whole relation tree. `query.max_includes` (default 3) limits the includes per call, and `query.max_include_depth` (default 2) limits depth: `lessons.author` is two, `lessons.author.company` is three. `query.enabled` turns the whole query layer off.

## Guarding it in tests

When a controller gains a filter and the agent schema does not, an assertion catches the drift:

```php
$this->assertQueryInputs(
    'list_catalog',
    filters: ['title', 'status', 'author'],
    sorts: ['title', '-created_at'],
    includes: ['lessons', 'lessonsCount'],
);
```

See [testing](/guide/testing).

## Feeding Scramble back

If you use `dedoc/scramble`, this package's query declarations can become OpenAPI query parameters, with the same names and descriptions the agent sees. See [documentation packages](/guide/documentation-packages#feeding-scramble).
