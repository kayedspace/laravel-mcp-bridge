# PHP API

The parts of the package you call or replace from application code. Everything else is internal and may change before 1.0.

## The facade

`KayedSpace\McpBridge\Facades\McpBridge` resolves `McpBridgeManager`.

| Method | Returns | Description |
| --- | --- | --- |
| `McpBridge::isMcpCall(?Request $request = null)` | `bool` | Whether the current request is an MCP tool call. Reads the `mcp.tool` request attribute set on route sub-requests. |
| `McpBridge::currentTool(?Request $request = null)` | `?string` | The tool name of the current call, or `null`. |
| `McpBridge::manifest()` | `ManifestRepository` | The compiled manifest. |
| `McpBridge::actor()` | `?Authenticatable` | Who the in-flight call is running as. |
| `McpBridge::actingAs(Authenticatable $actor, callable $callback)` | `mixed` | Run a callback with an actor resolved as the authenticated user on the default guard, restoring the previous state afterwards. This is what keeps a command tool from running as nobody. |
| `McpBridge::version()` | `string` | The installed package version as Composer records it, or `0.0.0` when Composer does not know it. It is the version sent to clients in `serverInfo`. |

Application code tells an agent call from a REST call without forking a controller: a route tool's sub-request carries the `mcp.tool` request attribute, which `McpBridge::isMcpCall()` and `McpBridge::currentTool()` read, and `request()->attributes->get('mcp.tool')` reads directly. Only route tools make a request, so inside a command, action or model tool, or a job run synchronously, `McpBridge::actor()` is the one to ask: it is set for the length of the call.

`KayedSpace\McpBridge\McpBridgeManager::ignoreMigrations()` (static) stops the package loading its own migrations, for an application that published and owns them.

## HasMcpTokens

`KayedSpace\McpBridge\Concerns\HasMcpTokens`, for the user model agents connect as.

| Method | Description |
| --- | --- |
| `mcpTokens()` | A `MorphMany` of the user's `McpToken` records. |
| `createMcpToken(string $name, array $scopes, ?DateTimeInterface $expiresAt = null, ?string $client = null)` | Issues a token. Returns a `NewMcpToken` with `accessToken`, the stored `McpToken`, and `plainText`. The plain text is available only here. `$scopes` has no default, so every token says what it holds: pass `['*']` for every scope, and an empty list holds nothing. |
| `currentMcpToken()` | The token this request arrived with, or `null` for a user read from a session or a factory. This is what the scope check asks first. |
| `withMcpToken(McpToken $token)` | Set by the guard. |

The trait declares no `tokenCan()`, so it sits beside Sanctum's `HasApiTokens` on one model; the scope check falls back to `tokenCan()` only for a user without a package token.

`KayedSpace\McpBridge\Models\McpToken` also offers `McpToken::issue(Model $tokenable, string $name, array $scopes, ?DateTimeInterface $expiresAt = null, ?string $client = null)`, which returns the same `NewMcpToken` and requires scopes in the same way, `McpToken::findByPlainText()`, `can()`, `holdsEveryScope()`, `isUsable()`, `hasExpired()`, `markUsed()` and `revoke()`.

## Writing list queries

`KayedSpace\McpBridge\Query\ApiQuery::fromAttributes(?string $model = null)` builds the query a list endpoint declares, with Spatie when installed and plain Eloquent otherwise. See [list endpoints](/guide/list-endpoints#applying-the-declaration-so-it-cannot-drift). `KayedSpace\McpBridge\Query\QueryDefinition` is what a controller's static `mcpQuery()` returns.

## Documentation

`KayedSpace\McpBridge\Integrations\ScrambleParameters::register()` feeds the package's query declarations into Scramble's OpenAPI document. Call it once from a service provider. See [documentation packages](/guide/documentation-packages#feeding-scramble).

## Filament

`KayedSpace\McpBridge\Filament\McpBridgePlugin::make()` is a Filament plugin that adds one page, `KayedSpace\McpBridge\Filament\Pages\McpTokens`, where a signed-in user issues and revokes their own package tokens. Register it with `$panel->plugin(McpBridgePlugin::make())`. See [Filament](/guide/filament).

## Events

`KayedSpace\McpBridge\Events\ToolCalled`, with `call` (`ToolCall`), `result` (`ToolResult`), `outcome` (`Outcome`) and `durationMs`. `KayedSpace\McpBridge\Enums\Outcome` has the cases `Allowed`, `Refused` and `Failed`. See [audit and events](/guide/audit-and-events#the-event).

## Kill switch

`KayedSpace\McpBridge\Security\KillSwitch`, resolved from the container, is what `mcp-bridge:kill` drives.

| Method | Description |
| --- | --- |
| `kill(string $target)` | Switch off a tool name, `source:<key>`, `server:<key>` or `*`. |
| `revive(string $target)` | Turn one target back on. |
| `reviveAll()` | Clear everything. |
| `killed()` | The list of targets currently off. |
| `isKilled(ToolDefinition $definition)` | Whether a tool is off. |

## Contracts you can replace

Bind your own implementation in a service provider. Defaults are bound by the package.

| Contract | Default | Replace it to |
| --- | --- | --- |
| `Contracts\AuthorizesTools` | `Security\ScopeGate` | Change how visibility and the call gate are decided. Doing this changes the security model: read the [security page](/guide/security) first. |
| `Contracts\ExecutesTools` | `Execution\RouteExecutor` | Change how route calls are dispatched. |
| `Contracts\StoresAudit` | Database, log or null logger, by `audit.driver` | Send the audit trail somewhere else. One method: `record(AuditEntry $entry)`. |
| `Contracts\ReadsDocumentation` | `Integrations\DocumentationReader` (Scribe, then Scramble) | Support another documentation vocabulary. One method: `read(string $class, ?string $method = null): ?Documented`. |
| `Contracts\ReadsQueryDefinitions` | `Query\QueryReader` | Read allowed queries from somewhere else. |
| `Contracts\ConnectsRemoteServers` | `Remote\RemoteServers` | Reach remote MCP servers another way, or fake them in tests. |
| `Contracts\LocatesModules` | Not bound: the provider builds `ModuleRegistry` with `NwidartLocator`, `PathRepositoryLocator` and `DirectoryLocator` | Add a locator for an in-house module layout, by rebinding `ModuleRegistry`. See [adding a module locator](#adding-a-module-locator). |
| `Contracts\MapsRules` | Not bound: listed in `schema.rule_mappers` | Say what a validation rule means. See [mapping your own validation rules](#mapping-your-own-validation-rules). |

## Middleware around a call

`execution.middleware` lists classes that run after the gate has allowed a call and before the source runs it, in order, with the `ToolCall` as the passable, the way route middleware runs with a request. Return a `ToolResult` instead of calling `$next` to stop the call; the trail records it as a refusal.

```php
use Closure;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;

final class InitialisesTenant
{
    public function handle(ToolCall $call, Closure $next): ToolResult
    {
        $tenant = Tenant::ownedBy($call->actor);

        if ($tenant === null) {
            return ToolResult::error('MCP_BRIDGE_FORBIDDEN', 'This user belongs to no tenant. Do not retry.');
        }

        $tenant->makeCurrent();

        return $next($call);
    }
}
```

```php
// config/mcp-bridge.php
'execution' => [
    'middleware' => [App\Mcp\InitialisesTenant::class],
],
```

The log context is already set when a middleware runs, so anything it logs names the tool and the request id. A middleware is resolved through the container, so it can take dependencies in its constructor.

What a middleware sees, and what happens when something goes wrong:

- **Order.** The gate, then the [idempotency](/concepts/output-and-errors#retries-and-idempotency-keys) claim, then the middleware, then the source. An idempotent replay returns before any middleware runs, so a metering or tracing middleware does not see a replay.
- **An exception.** An exception thrown anywhere in the pipeline (a middleware, the source, the shaping) is caught by `ToolRunner` and reported to the application's exception handler. The agent receives `MCP_BRIDGE_SERVER_ERROR`, with the exception message only when `security.expose_error_details` is on. The audit records the call as `failed`, and the idempotency key is released, so a retry runs.
- **A missing class.** A class listed in `execution.middleware` that does not exist stops every call with `MCP_BRIDGE_SERVER_ERROR` naming the class. Skipping it would run calls without the tenancy or the check it was meant to add, so the package fails closed, and [`mcp-bridge:doctor`](/reference/commands#mcp-bridge-doctor) reports it under "Call middleware".

## Mapping your own validation rules

A custom rule object carries logic no schema can express, so by default it becomes a note on the field saying so. A class implementing `KayedSpace\McpBridge\Contracts\MapsRules` says what the rule means instead: `new Postcode` is a string with a pattern, `new OneOfOurStatuses` is an enum.

```php
use KayedSpace\McpBridge\Contracts\MapsRules;
use KayedSpace\McpBridge\Data\Field;

class PostcodeMapper implements MapsRules
{
    public function handles(mixed $rule): bool
    {
        return $rule instanceof PostcodeRule;
    }

    public function map(Field $field, mixed $rule): Field
    {
        return $field->with(
            description: 'A UK postcode, such as SW1A 1AA.',
            pattern: '^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$',
        );
    }
}
```

```php
// config/mcp-bridge.php
'schema' => [
    'rule_mappers' => [App\Mcp\PostcodeMapper::class],
],
```

`handles()` receives each rule as validation was given it: a string such as `max:255`, or a rule object. Mappers are resolved from the container and asked in the order listed, before the built-in mapping, so a mapper can also change how one of Laravel's own rules is read. The first mapper whose `handles()` returns `true` maps that rule, and neither later mappers nor the built-in mapping see it. A listed class that does not implement `MapsRules` is skipped, so a typo does not stop the manifest building. Mappers run at compile time: rebuild a cached manifest after changing one.

## Adding a module locator

`ModuleRegistry` is built by the service provider with the three shipped locators, keyed by the names `modules.locators` refers to. A locator implements `KayedSpace\McpBridge\Contracts\LocatesModules`, whose one method returns `Data\Module` objects with a name and a path. To add one, rebind the registry in a service provider's `register()` method and pass every locator you want asked:

```php
use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Modules\DirectoryLocator;
use KayedSpace\McpBridge\Modules\ModuleRegistry;
use KayedSpace\McpBridge\Modules\NwidartLocator;
use KayedSpace\McpBridge\Modules\PathRepositoryLocator;

public function register(): void
{
    $this->app->singleton(ModuleRegistry::class, fn ($app) => new ModuleRegistry(
        locators: [
            'nwidart' => $app->make(NwidartLocator::class),
            'path-repositories' => $app->make(PathRepositoryLocator::class),
            'directories' => $app->make(DirectoryLocator::class),
            'domains' => $app->make(DomainLocator::class), // yours, implementing LocatesModules
        ],
        config: $app->make(Repository::class),
    ));
}
```

Then name it in the configuration. The shipped `modules.locators` is the explicit list of the three shipped locators, so add the new key to it, `'locators' => ['nwidart', 'path-repositories', 'directories', 'domains']`, or set it to `['*']` to ask every registered locator. Your provider must register after this package's, which is the case for an application provider. Before writing one, check whether `modules.directories` already covers the layout.

## Sources

A new kind of tool source implements `KayedSpace\McpBridge\Contracts\Source`:

```php
public function key(): string;                              // 'route', 'command', ...
public function discover(): iterable;                       // ToolCandidate, deterministic order
public function schema(ToolCandidate $candidate): InputSchema;
public function validate(ToolCandidate $candidate): array;  // list<Refusal>
public function execute(ToolCall $call): SourceResult;
```

Register it with the `SourceRegistry` in a service provider, add a `sources.<key>` block to the configuration with `enabled` set to `false`, and say which server it belongs on.

```php
use KayedSpace\McpBridge\Sources\SourceRegistry;

$this->app->afterResolving(SourceRegistry::class, fn (SourceRegistry $registry) => $registry->register(new BusSource(/* ... */)));
```

Unless the new source inherits HTTP middleware the way a route does, `AuthorityRequirement` will refuse a candidate that declares no scope and no ability. That is intended: do not work around it. Keep `discover()` deterministic (sort by candidate id), return refusals from `validate()` rather than silently skipping, and read declarations rather than run them: never call a controller or a handler to find out about a candidate. The few places the shipped sources do run code at compile time (`rules()`, remote and OpenAPI fetches, Scramble's generator) are listed under [discovery](/concepts/how-it-works#_1-discovery). The `add-a-source` skill in the package repository walks through the steps.
