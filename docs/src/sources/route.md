# Routes

HTTP routes are the primary source, and the only one that follows the configured [`mode`](/reference/configuration#top-level). A route arrives already wrapped in middleware, a guard, a FormRequest and usually a policy, and a tool call inherits all of it.

## What it exposes

Any route bound to a controller action: `[CourseController::class, 'index']`, or an invokable controller. Closure routes are never tools, because they carry no attributes and no FormRequest to infer a schema or an opt-in from.

A route must also pass the structural filter in `discovery`:

| Key | Effect |
| --- | --- |
| `prefixes` | The URI must equal a prefix or start with `prefix/`. Default `['api']`; `*` is any. |
| `include` | The route's URI, name or controller must match one of these `Str::is` patterns. Default `['*']`. |
| `exclude` | A match on URI, name or controller removes the route. Default `['api/*/webhooks/*', 'api/internal/*']`. |
| `methods` | HTTP methods considered; `*` is any. `HEAD` and `OPTIONS` are never tools. |
| `domains` | Hosts to discover from. Default `['*']`. |
| `middleware` | Patterns a route's middleware must all match. A requirement list, so empty requires nothing. |

Every allowlist reads the same way: `*` is everything and an empty list is nothing. `exclude` is the other kind and takes away from what the allowlists granted. A wildcard in `prefixes` also declares no prefix to strip from derived names, so `api/v1/courses` becomes `list_api_courses` rather than `list_courses`; name the real prefix, or name the tools with `#[McpTool(name: ...)]`.

When one route answers both `PUT` and `PATCH`, they collapse into a single update tool.

## What it needs to be opted in

In the default `opt-in` mode, `#[McpTool]` on the controller action, or on the controller class to cover every action in it (a method-level attribute overrides the class). `#[McpIgnore]` excludes a route in any mode and wins over every opt-in. What `read-only` and `opt-out` expose is on the [security page](/guide/security#routes-follow-the-mode).

Scopes and abilities are optional here. Declare `#[McpScope]` and `#[McpAbility]` when you want the tool to demand more than the route does, for example a narrow token scope for an agent.

## Example

An ordinary controller. Nothing is written for agents except the attributes.

```php
use KayedSpace\McpBridge\Attributes\AllowFilter;
use KayedSpace\McpBridge\Attributes\AllowSort;
use KayedSpace\McpBridge\Attributes\McpConfirm;
use KayedSpace\McpBridge\Attributes\McpTool;
use KayedSpace\McpBridge\Query\ApiQuery;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

class CourseController extends Controller
{
    #[McpTool(description: 'List courses. Filter by title or status, sort, and page through them.')]
    #[AllowFilter('title')]
    #[AllowFilter('status', type: 'exact')]
    #[AllowSort('title')]
    #[AllowSort('created_at', default: 'desc')]
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => ApiQuery::fromAttributes(Course::class)->paginate()->items()]);
    }

    #[McpTool(description: 'Read one course by its id.')]
    public function show(Course $course): JsonResponse
    {
        return new JsonResponse(['data' => $course]);
    }

    #[McpTool(description: 'Create a draft course.')]
    public function store(CourseStoreRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => Course::query()->create($request->validated())], 201);
    }

    #[McpTool(description: 'Delete a course for good.')]
    #[IsDestructive]
    #[McpConfirm('Permanently delete this course?')]
    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return new JsonResponse(null, 204);
    }
}
```

With routes named `api.courses.index`, `api.courses.store`, `api.courses.show` and `api.courses.destroy`, this compiles to `list_courses`, `create_course`, `get_course` and `delete_course`. `list_courses` offers the `title` and `status` filters and the two sorts, and `ApiQuery` applies the same declaration to the real query, so the two cannot drift. The `store` schema is the rules of `CourseStoreRequest`, `show` takes a typed `course` id, and `destroy` asks for confirmation before it runs.

Customising what the agent sees, with the attributes that apply to routes:

```php
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpExample;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Attributes\McpRateLimit;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(name: 'publish_course', description: 'Publish a course.')]
#[McpScope('courses:write')]
#[McpAbility('manage-courses')]
#[McpRateLimit(10)]
#[McpParam('note', description: 'Shown to subscribers.', example: 'Now live')]
#[McpExample(input: ['course' => 12], description: 'publish course 12')]
public function publish(int $course): JsonResponse { /* ... */ }
```

## How a route tool runs

`RouteExecutor` builds a request for the route's URI and verb, substitutes path parameters, puts query inputs in the query string and write inputs in a JSON body, and hands it to the HTTP kernel. The route behaves exactly as for a REST client: the global middleware (trimmed input, maintenance mode, whatever your application adds, such as tenant or locale resolution), the route's own middleware, FormRequest validation and policies all run, and an exception the route throws reaches your exception handler, so it is reported and rendered as it would be for any request.

- The request is made on the outer request's scheme and host, with the caller's IP address, so a URL the route builds points at the application and an IP-keyed throttle sees the agent. From a console (`mcp-bridge:call`, a stdio server) the host is `app.url`.
- The outer `Authorization` header is forwarded, and the already-authenticated user is handed to each guard in `security.guards`, so the route's `auth:` middleware passes without authenticating twice.
- The headers in `execution.forward_headers` (`Accept-Language` and `User-Agent` by default) are copied from the outer request. Nothing else is: the outer request is the MCP call, and most of what it carries describes that call.
- The request carries `X-Request-Id`, `X-Mcp-Tool` and, when the client named itself, `X-Mcp-Client`. In your controller, `$request->isMcp()`, `$request->mcpTool()` and `$request->mcpClient()` read them; see [request macros](/reference/php-api#request-macros).
- A route must answer JSON. HTML produces `MCP_BRIDGE_NON_JSON`, and a redirect is reported as a failure naming where it went.
- The JSON your exception handler renders for a validation error, a missing model, an authorisation failure, an HTTP exception or maintenance mode is mapped to a [tool error](/concepts/output-and-errors). A missing model's class name is removed from the message.
- Terminating middleware does not run for the sub-request, because Laravel's own would flush what the outer request deferred. What a route defers with `defer()` still runs, at the end of the request that carried the tool call.
- Your code can [tell an agent call from a REST call](/reference/php-api#the-facade) without forking a controller.

## Safety is inferred from the verb

`GET` is read-only and idempotent, `PUT` is idempotent, `DELETE` is destructive and idempotent, anything else is a plain write. `DELETE` therefore asks for confirmation by default. A `POST` that destroys data is not inferred as destructive, so say so:

```php
#[McpTool(description: 'Purge archived courses.')]
#[IsDestructive]
#[McpConfirm('Permanently purge every archived course?')]
public function purge(): JsonResponse { /* ... */ }
```

## List endpoints, files and documentation

- A list endpoint becomes a typed, filterable tool. See [list endpoints](/guide/list-endpoints).
- A `file` or `image` rule becomes a staged-upload id. See [file uploads](/guide/uploads).
- Scramble and Scribe annotations enrich the schema and can hide a route. See [documentation packages](/guide/documentation-packages).

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `mode` | `opt-in` | Which discovered routes become tools. |
| `sources.route.enabled` | `true` | Switch the whole source off. |
| `discovery.*` | see above | The structural filter. |
| `security.guards` | `['mcp-bridge', 'sanctum']` | Guards handed the authenticated user. |
| `execution.forward_headers` | `['Accept-Language', 'User-Agent']` | Outer request headers copied onto the sub-request. |
