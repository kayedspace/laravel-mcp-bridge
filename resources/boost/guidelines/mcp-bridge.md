## MCP Bridge

This application exposes its own routes, commands, jobs, actions and models to
AI agents as MCP tools, through `kayedspace/laravel-mcp-bridge`. Four things
hold whatever you are changing:

- **Exposure is opt-in.** `#[McpTool]` on a controller action exposes it. No
  attribute, no tool. Do not widen `mcp-bridge.mode` to expose something.
- **Rebuild a cached manifest after changing exposure.** Without
  `bootstrap/cache/mcp-bridge.php` the agent surface compiles on demand; with
  one (production, or after `php artisan mcp-bridge:cache` or `optimize`) an
  attribute nobody compiled does nothing until one of them runs again. `mcp-bridge:tools
  --refused` says why something is missing.
- **Anything that is not an HTTP route needs authority of its own.** A
  command, job, action or model tool must declare both `#[McpScope]` and
  `#[McpAbility]`, and a model tool also needs a policy method for the
  operation. A route inherits its middleware, guard and policy; the others
  have nothing to inherit.
- **Do not write a `laravel/mcp` Tool class for something this already
  covers.** The schema comes from the FormRequest, the route parameters and
  the allowed query. Hand-writing one duplicates all of it and drifts.

Run `php artisan mcp-bridge:lint` before you believe a change works. For
anything beyond this, read the `mcp-bridge` skill.
