<picture>
  <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark-mode.png">
  <img alt="Laravel MCP Bridge: turn your Laravel app into an MCP server for AI agents. Your authentication, gates and policies still decide." src="art/banner-light-mode.png">
</picture>

<p align="center">
  <a href="https://github.com/kayedspace/laravel-mcp-bridge/actions/workflows/tests.yml"><img src="https://github.com/kayedspace/laravel-mcp-bridge/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://packagist.org/packages/kayedspace/laravel-mcp-bridge"><img src="https://img.shields.io/packagist/v/kayedspace/laravel-mcp-bridge" alt="Latest Stable Version"></a>
  <a href="https://packagist.org/packages/kayedspace/laravel-mcp-bridge"><img src="https://img.shields.io/packagist/dt/kayedspace/laravel-mcp-bridge" alt="Total Downloads"></a>
  <a href="https://packagist.org/packages/kayedspace/laravel-mcp-bridge"><img src="https://img.shields.io/packagist/l/kayedspace/laravel-mcp-bridge" alt="License"></a>
</p>

## Introduction

Your Laravel application already describes every input with FormRequests and decides every call with guards, gates and policies. Laravel MCP Bridge serves what it already declares to AI agents as MCP tools, without a second copy of either. It is built on the official [`laravel/mcp`](https://github.com/laravel/mcp) package, which keeps the transport and the protocol.

```php
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'Create a draft course.')]
public function store(CourseStoreRequest $request)
{
    // unchanged
}
```

The agent now sees a `create_course` tool, typed from `CourseStoreRequest`. When it calls the tool, the call runs through your route: your middleware authenticates it, the FormRequest validates it and your policy authorizes it. Nothing is exposed until you mark it, and your existing routes and responses stay exactly as they are.

## Installation

```bash
composer require kayedspace/laravel-mcp-bridge
php artisan mcp-bridge:install
```

Add the token trait to the model your agents connect as:

```php
use KayedSpace\McpBridge\Concerns\HasMcpTokens;

class User extends Authenticatable
{
    use HasMcpTokens;
}
```

Mark a route with `#[McpTool]`, check what is exposed, then connect a client:

```bash
php artisan mcp-bridge:tools --refused
php artisan mcp-bridge:token you@example.com --name=Claude --scopes=courses:read
php artisan mcp-bridge:connect claude --token=mcpb_... --apply
```

`--apply` runs `claude mcp add` for you. For Cursor and VS Code it writes the client's JSON file. The [getting started guide](https://laravel-mcp-bridge.kayed.dev/guide/getting-started) walks through the same steps in more detail.

## Sources

Eight sources can produce tools. Each one is switched on in `config/mcp-bridge.php`, and only routes and resources are on after install.

| Source | Exposes | How an item is exposed | Served on |
| --- | --- | --- | --- |
| `route` | HTTP routes, with their middleware, guard and policies | Follows the exposure mode | `/mcp` |
| `resource` | Read-only context such as routes, schedule and one record | Per resource | `/mcp` |
| `command` | Artisan commands | Per class, with a scope and an ability | `/mcp/ops` |
| `job` | Queued jobs, dispatched as the agent's user | Per class, with a scope and an ability | `/mcp/ops` |
| `action` | Single-purpose action classes | Per class, with a scope and an ability | `/mcp/ops` |
| `model` | Eloquent models, through their policies | Per model, with a scope, an ability and a policy method | `/mcp/ops` |
| `remote` | Tools re-exported from another MCP server | Per server | `/mcp/ops` |
| `openapi` | Operations of an API the application calls | Per document | `/mcp/ops` |

## Two servers

The package serves two MCP endpoints, so the tools a product agent needs and the tools an operator needs never share one catalog or one token.

| Server | Path | Serves | Meant for |
| --- | --- | --- | --- |
| `default` | `/mcp` | Your API routes and resources | Agents acting for your users, with the same rights those users have in the app |
| `ops` | `/mcp/ops` | Commands, jobs, actions, models, remote servers and OpenAPI documents | Operators and internal agents running the application |

Each server has its own middleware and rate limiter (`throttle:mcp` and `throttle:mcp-ops`) and both authenticate with the package's token guard. A tool can move to another server with `#[McpGroup]`, a source can move in `servers.*.sources`, and you can add servers of your own. See [servers and modules](https://laravel-mcp-bridge.kayed.dev/guide/servers-and-modules).

### Already using laravel/mcp?

Your own servers keep working beside the package's. To serve your hand-written `laravel/mcp` tools, resources and prompts on the same endpoint as the generated tools, list them on a package server:

```php
'servers' => [
    'default' => [
        'tools' => [App\Mcp\Tools\CurrentWeather::class],
        'resources' => [App\Mcp\Resources\StaffHandbook::class],
    ],
],
```

## Exposure modes

The `mode` setting, or `MCP_BRIDGE_MODE`, decides which routes become tools:

| Mode | Routes exposed |
| --- | --- |
| `opt-in` (default) | Only routes marked with `#[McpTool]`, on the action or on the whole controller |
| `read-only` | Marked routes, plus every discovered `GET` route |
| `opt-out` | Every discovered route, except those marked `#[McpIgnore]` or excluded from your API docs |

Switching the whole API to opt-out is one line:

```dotenv
MCP_BRIDGE_MODE=opt-out
```

Discovery decides which routes are candidates in the first place: by default every route under `api/`, minus webhooks and `api/internal/*`, adjustable under `discovery`. `#[McpIgnore]` keeps a route out in every mode.

The mode applies to routes only. Commands, jobs, actions and models stay opt-in per class in every mode, because they have no middleware or policy to inherit, and an app-wide switch should never hand an agent an Artisan command.

List endpoints come out typed. Allowed filters, sorts, includes and fields become structured inputs, read from attributes, from route model binding, or from an existing Spatie Query Builder chain by static analysis. Scramble and Scribe attributes feed the same schema.

## Authorization

A route arrives already protected, so a route tool inherits everything: middleware, guard, FormRequest and policy. A tool can take permissions away, never add one.

Commands, jobs, actions and models have nothing to inherit, so each one must declare who may run it, in every mode:

```php
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'Recalculate course statistics for a date range.')]
#[McpScope('ops:write')]
#[McpAbility('run-reports')]
class RecalculateStats extends Command
{
    // unchanged
}
```

Without both attributes the command is not exposed, and `mcp-bridge:lint` fails your CI.

To decide who may use MCP at all, for example only subscribers, define an `accessMcp` gate. It covers every tool on a server, and receives the server's key:

```php
Gate::define('accessMcp', fn (User $user, string $server): bool => $user->subscribed());
```
 A model tool also needs a policy method for each operation. Scopes and abilities are checked when tools are listed and again on every call.

Agents retry and guess, so the package plans for it:

- Destructive tools answer first with a preview and a single-use confirmation handle.
- Writes accept an `idempotency_key`, so a retried create returns the first answer.
- An argument the tool does not declare is refused, with the names it accepts.
- Revoking a token stops jobs it has already queued.

The [security guide](https://laravel-mcp-bridge.kayed.dev/guide/security) covers the full model.

## Operating it

Tokens are scoped and expire after 90 days by default. Issue them from the CLI, from the panel at `/mcp-bridge`, behind a gate you define, or from Filament. Sanctum and Passport tokens work too.

Every call writes one audit row, with sensitive arguments masked, and fires a `ToolCalled` event for metrics and alerts. When an agent misbehaves, switch a tool off without a deploy:

```bash
php artisan mcp-bridge:kill delete_course
php artisan mcp-bridge:kill delete_course --revive
```

The package's checks appear in `php artisan doctor` when [`laravel/doctor`](https://github.com/laravel/doctor) is installed, and `mcp-bridge:doctor` runs them on its own.

## Testing

```php
use App\Models\User;
use KayedSpace\McpBridge\Testing\InteractsWithMcp;

uses(InteractsWithMcp::class);

it('exposes the catalogue the way we meant', function () {
    $this->assertToolExists('list_courses')
        ->assertToolReadOnly('list_courses')
        ->assertToolNeedsConfirmation('delete_course');

    $result = $this->callMcpTool('list_courses', ['per_page' => 2], as: User::factory()->create());

    expect($result->isError)->toBeFalse();
});

it('keeps its agent contract', function () {
    expect($this->mcpContract())->toMatchSnapshot();
});
```

`callMcpTool()` goes through the same runner a connected client does, so a test cannot prove more than the agent gets. The contract snapshot fails when a tool's name, schema or hints change without anyone meaning them to.

## AI coding agents

When [Laravel Boost](https://github.com/laravel/boost) is installed, it picks up this package's guidelines and skill automatically, so your coding agent knows how to expose an endpoint and which mistakes to avoid.

## Documentation

The full documentation lives at **[laravel-mcp-bridge.kayed.dev](https://laravel-mcp-bridge.kayed.dev)**.

| Page | What it covers |
| --- | --- |
| [Getting started](https://laravel-mcp-bridge.kayed.dev/guide/getting-started) | Install to a working tool |
| [How it works](https://laravel-mcp-bridge.kayed.dev/concepts/how-it-works) | Discovery, schema, compilation and running |
| [Sources](https://laravel-mcp-bridge.kayed.dev/sources/) | One page per source |
| [Attributes](https://laravel-mcp-bridge.kayed.dev/reference/attributes) | All twenty, and what reads each one |
| [Configuration](https://laravel-mcp-bridge.kayed.dev/reference/configuration) | Every key and its default |
| [Commands](https://laravel-mcp-bridge.kayed.dev/reference/commands) | All sixteen Artisan commands |
| [Security](https://laravel-mcp-bridge.kayed.dev/guide/security) | The authority model in full |
| [Troubleshooting](https://laravel-mcp-bridge.kayed.dev/guide/troubleshooting) | When a tool does not appear or a call is refused |

## Requirements

- PHP 8.3 or higher
- Laravel 12.41.1 or higher, or Laravel 13
- `laravel/mcp` 1.1, MCP revision 2026-07-28

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for how the package is put together. `composer serve` starts a workbench app with a live `/mcp` endpoint, and `composer test` runs the whole gate.

## Security

Please report security issues through a private security advisory on this repository rather than a public issue. See [SECURITY.md](.github/SECURITY.md).

## Credits

- [Ali Youssef Kayed](https://github.com/kayedspace)

## License

Laravel MCP Bridge is open-sourced software licensed under the [MIT license](LICENSE).
