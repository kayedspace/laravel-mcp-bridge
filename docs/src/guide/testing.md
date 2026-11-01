# Testing

Test your agent surface the way an agent reaches it. The package ships a trait, `InteractsWithMcp`, whose helpers compile your declarations fresh and call tools through the same `ToolRunner` a connected client uses. A test cannot prove more than the agent gets.

```php
use App\Models\User;
use KayedSpace\McpBridge\Testing\InteractsWithMcp;

class CatalogToolsTest extends TestCase
{
    use InteractsWithMcp;

    public function test_the_catalogue_is_exposed_the_way_we_meant(): void
    {
        $this->assertToolExists('list_courses')
            ->assertToolReadOnly('list_courses')
            ->assertToolNeedsConfirmation('delete_course')
            ->assertQueryInputs('list_courses', filters: ['title', 'status'], sorts: ['-created_at']);

        // A route tool with no #[McpScope], so a factory user is enough.
        $result = $this->callMcpTool('list_courses', ['per_page' => 2], as: User::factory()->create());

        $this->assertFalse($result->isError);
    }
}
```

The assertions return `$this`, so they chain. Every helper is listed in the [testing reference](/reference/testing).

## Fresh, not cached

The helpers compile on demand rather than reading the cached [manifest](/concepts/how-it-works#glossary) in `bootstrap/cache/mcp-bridge.php`, once per test. The question a test asks is whether the code is right, and a stale manifest would answer about the last build. If a test changes configuration partway, call `refreshMcpTools()` to forget the compiled surface.

## What to assert

**What is exposed, and what is not.** The most valuable test is the one that fails when someone exposes something by accident.

```php
$this->assertToolExists('list_courses');
$this->assertToolMissing('internal_course');
```

**That something was refused on purpose.** A refused [candidate](/concepts/how-it-works#glossary) is different from one that was never found. Name the candidate id and, optionally, the code:

```php
$this->assertToolRefused('route:api.v1.courses.internal:GET', 'MCP_BRIDGE_NOT_EXPOSED');
$this->assertToolRefused('route:api.v1.courses.export:GET', 'MCP_BRIDGE_IGNORED');
```

Use `mcp-bridge:tools --refused` to find the candidate ids and codes.

**Inputs, safety and authority.**

```php
$this->assertToolInputs('create_course', ['title', 'status', 'price']);
$this->assertToolReadOnly('list_courses');
$this->assertToolNeedsConfirmation('purge_course');
$this->assertToolRequiresScopes('publish_course', ['courses:write']);
```

**List endpoints keep their filters.** `assertQueryInputs()` fails when a controller gains a filter and the agent schema does not:

```php
$this->assertQueryInputs(
    'list_catalog',
    filters: ['title', 'status', 'author'],
    sorts: ['title', '-created_at'],
    includes: ['lessons', 'lessonsCount'],
);
```

## Calling a tool

`callMcpTool()` runs a tool as a given user and returns a `ToolResult` with `text`, `structured`, `isError`, `errorCode` and `meta` (which carries `replayed` for an idempotent replay and `truncated` for a trimmed list):

```php
$result = $this->callMcpTool('get_course', ['course' => 3], as: $user);

$this->assertSame(3, $result->structured['data']['id']);
```

When you do not pass `as:`, it uses `auth()->user()`, so `actingAs($user)` works. When there is no one to call as it fails the test with "needs someone to call it as", rather than guess: a tool that cannot name its actor does not run.

Because the call goes through the gate, refusals are testable as the agent would meet them:

```php
$result = $this->callMcpTool('create_course', ['title' => 'x'], as: $userWithoutTheScope);

$this->assertTrue($result->isError);
$this->assertSame('MCP_BRIDGE_SCOPE', $result->errorCode);
```

A real call also writes an audit entry and fires a `ToolCalled` event with `clientName` set to `tests`.

### Tools that declare a scope

A [scope](/concepts/how-it-works#glossary) is held by a token, not by a user. A user from a factory carries no token, so a tool that declares `#[McpScope]` refuses it, which is the fail-closed rule doing its job. The refusal says why:

| The test actor | Refused with |
| --- | --- |
| A `HasMcpTokens` user with no token attached | "the caller holds no token" |
| A `HasApiTokens` user whose Sanctum token lacks the scope | "needs the scope [...]" |
| A user with neither trait | "the current guard cannot report token abilities" |

The full rule is on the [security page](/guide/security#a-guard-that-cannot-report-abilities-fails-closed).

#### With package tokens

Give the test actor a package token the way the guard does, with `withMcpToken()` from `HasMcpTokens`:

```php
use KayedSpace\McpBridge\Models\McpToken;

$user = User::factory()->create();

// A token that is never saved, holding only the scope under test.
$user->withMcpToken((new McpToken)->forceFill(['scopes' => ['courses:write']]));

$result = $this->callMcpTool('publish_course', ['course' => 12], as: $user);
```

`$user->withMcpToken($user->createMcpToken('test', ['courses:write'])->accessToken)` does the same with a saved token, for a test that also asserts on the token row. Leave the scope out of the list to test the refusal. Gate [abilities](/concepts/how-it-works#glossary) need no token: your own Gate answers them for the user.

#### With Sanctum

For an actor with Sanctum's `HasApiTokens` and no package token, the scope check asks `tokenCan()`, so Sanctum's own helper gives the actor a token with the scopes as its abilities. Leave `as:` out and the call runs as the user Sanctum authenticated:

```php
use Laravel\Sanctum\Sanctum;

Sanctum::actingAs($user, ['courses:read']);

$result = $this->callMcpTool('list_courses');
```

### Destructive tools

A destructive tool answers the first call with a confirmation request, not an error:

```php
$preview = $this->callMcpTool('purge_course', as: $user);

$this->assertSame('MCP_BRIDGE_CONFIRMATION_REQUIRED', $preview->errorCode);
```

`confirmMcpTool()` walks the whole handshake the way an agent does, asking once and calling again with the handle:

```php
$result = $this->confirmMcpTool('purge_course', as: $user);

$this->assertFalse($result->isError);
```

If a tool did not ask for confirmation it has already run, and `confirmMcpTool()` returns that first result rather than calling twice. To test the handle itself, pass it to `callMcpTool(..., confirm: $handle)`.

### Retries with an idempotency key

Pass the key as `idempotencyKey:`, the way an agent sends `idempotency_key`. The same key, tool, actor and arguments answer with the first result, and no second course is created:

```php
$user->withMcpToken((new McpToken)->forceFill(['scopes' => ['courses:write']]));

$first = $this->callMcpTool('create_course', ['title' => 'Immunology'], as: $user, idempotencyKey: 'enrol-42');
$retry = $this->callMcpTool('create_course', ['title' => 'Immunology'], as: $user, idempotencyKey: 'enrol-42');

$this->assertTrue($retry->meta['replayed']);
```

How keys are bound, released and replayed is in [retries and idempotency keys](/concepts/output-and-errors#retries-and-idempotency-keys).

## Pinning the contract

A tool's description, input schema, output schema and hints are the contract with every agent that uses your application. Pest already snapshots, so the package only supplies the subject:

```php
it('keeps its agent contract', fn () => expect($this->mcpContract())->toMatchSnapshot());
```

`mcpContract()` is what a client is handed: for each tool, sorted by name, its `description`, `inputSchema`, `outputSchema` and `annotations`. It is deliberately **not** the manifest, which also carries candidate ids, targets and which config layer set each option. All of those move when a class is renamed or a default is edited, and a snapshot that fails for them is a snapshot a team learns to re-record without reading.

When the snapshot changes, read the diff the way an agent would: a changed description changes which tool is chosen, and a changed input changes what is sent.

## Testing your own policies and gates

The helpers prove that a tool is wired to your [authority](/concepts/how-it-works#glossary), not that the authority is right. Test your Gate abilities and policies the way you already do. The package guarantees that they are consulted, on listing and on calling, and `assertToolRequiresScopes()` plus a refusal test is enough to pin that.
