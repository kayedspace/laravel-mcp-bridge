# Adopting it in an existing application

An application that already has an API, commands and a domain layer has the most to expose and the most to expose by accident. Do it in small steps, with the lint as your safety net.

1. **Install, then look before you change anything:**

   ```bash
   composer require kayedspace/laravel-mcp-bridge
   php artisan mcp-bridge:install
   php artisan mcp-bridge:tools --refused
   ```

   In the default `opt-in` mode nothing is exposed until you opt it in. `--refused` shows what was found.
2. **Set the discovery guards** before anything is exposed, so a route that should never be a tool is never even a candidate:

   ```php
   // config/mcp-bridge.php
   'discovery' => [
       'prefixes' => ['api'],
       'middleware' => ['auth:*'],                     // nothing unauthenticated becomes a tool
       'exclude' => ['api/*/webhooks/*', 'api/internal/*', 'api/admin/*'],
   ],
   ```

   `discovery.middleware` set to `['auth:*']` keeps every route without a guard out. `discovery.include` and `discovery.exclude` narrow by URI, route name or controller, with `Str::is` patterns.

   To preview what a wider mode would expose without changing the configuration, run `MCP_BRIDGE_MODE=read-only php artisan mcp-bridge:tools` with no cached manifest or configuration: every `GET` route that passes the guards is listed as a tool. `mcp-bridge:tools --refused` lists the rest, each with its reason. Neither changes what a client sees. The keys are in the [configuration reference](/reference/configuration#discovery), and the modes in [the security model](/guide/security#routes-follow-the-mode).
3. **Expose one read endpoint** and call it the way an agent would:

   ```php
   use KayedSpace\McpBridge\Attributes\McpTool;

   #[McpTool(description: 'List courses. Filter by title or status, sort, and page through them.')]
   public function index(CourseIndexRequest $request) { /* unchanged */ }
   ```

   ```bash
   php artisan mcp-bridge:call list_courses --arg=per_page=2
   ```

   Locally the manifest compiles on demand, so there is nothing to rebuild. Compare the answer with the REST response.
4. **Check the schema against your REST docs.** `mcp-bridge:export --format=markdown` shows exactly what an agent reads. If you use Scramble or Scribe, `mcp-bridge:lint` reports where your documentation and your MCP attributes disagree.
5. **Add write endpoints.** The FormRequest is the schema and the same rules reject the same input. A `DELETE` route is destructive already. Mark any other destructive operation so it confirms, with a message that names the consequence:

   ```php
   use KayedSpace\McpBridge\Attributes\McpConfirm;
   use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

   #[McpTool(description: 'Archive a course and unenrol every student.')]
   #[IsDestructive]
   #[McpConfirm('Archive this course and unenrol its students?')]
   public function archive(Course $course) { /* unchanged */ }
   ```

   See [destructive calls and confirmation](/guide/security#destructive-calls-and-confirmation).
6. **Issue a token and connect a client.** Scopes are always stated: `*` is every scope, and an empty list holds nothing.

   ```bash
   php artisan mcp-bridge:token you@example.com --name=Claude --scopes=courses:read --expires=30d
   php artisan mcp-bridge:connect claude --token=mcpb_... --apply
   ```

   The first prints the token once. The second connects Claude Code on this machine, or prints the configuration to paste without `--apply`. A signed-in user issues their own on the [token panel](/guide/panel). See [connecting a client](/guide/connecting-clients).
7. **Only then consider non-route sources.** Each needs `#[McpScope]` and `#[McpAbility]`, and lands on the `ops` server behind `auth:mcp-bridge` unless you place it elsewhere ([where a tool lands](/guide/servers-and-modules#where-a-tool-lands)):

   ```php
   #[McpTool(description: 'Recalculate course statistics for a date range.')]
   #[McpScope('ops:write')]
   #[McpAbility('run-reports')]
   class RecalculateStats extends Command { /* unchanged */ }
   ```

   Then `sources.command.enabled` to `true`, `Gate::define('run-reports', ...)` in a service provider, and a token holding `ops:write` for the agent that may run it. Read the [security model](/guide/security) first. Model tools carry a `model_` prefix (`model_get_course`), so they sit beside the routes for the same model; a name another tool already holds costs only that tool, and `mcp-bridge:tools --refused` shows it ([collisions](/concepts/naming#collisions)).
8. **Pin the surface in tests** with `mcpContract()` and a snapshot, and run `mcp-bridge:lint` in CI. See [testing](/guide/testing).

If your API already uses Spatie Query Builder, your list endpoints need no attributes. If it uses `dedoc/scramble` or Scribe, your documented parameters are already read. See [list endpoints](/guide/list-endpoints) and [documentation packages](/guide/documentation-packages).

Before the first production deploy, read [operating in production](/guide/operations): the deploy step, the checklist and the kill switch.
