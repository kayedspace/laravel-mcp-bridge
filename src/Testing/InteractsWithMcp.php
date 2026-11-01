<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Execution\ToolRunner;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Server\GeneratedTool;
use PHPUnit\Framework\Assert;

/**
 * Testing an application's own agent surface.
 *
 * Everything here compiles fresh from the application's declarations rather
 * than reading a cached manifest, because the question a test asks is whether
 * the code is right, and a stale manifest would answer about the last build.
 *
 * Calls go through the same runner a connected client does, gate and audit
 * included. A helper that skipped the gate would let a test prove a tool
 * works while the agent is refused it.
 */
trait InteractsWithMcp
{
    protected ?CompileResult $mcpCompiled = null;

    /** Compiled once per test, since discovery walks every route and class. */
    public function mcpTools(): CompileResult
    {
        return $this->mcpCompiled ??= app(ManifestCompiler::class)->compile();
    }

    /** Forget the compiled surface, for a test that changes configuration partway. */
    public function refreshMcpTools(): void
    {
        $this->mcpCompiled = null;
    }

    /**
     * Everything an agent can see, and nothing it cannot, ready to snapshot.
     *
     * Pest already has `toMatchSnapshot()`, so this is the subject rather
     * than a snapshotting mechanism of its own:
     *
     *     it('keeps its agent contract', fn () => expect(mcpContract())->toMatchSnapshot());
     *
     * It deliberately is not the manifest. A manifest carries candidate ids,
     * targets and which config layer set each option, all of which move when
     * a class is renamed or a default is edited, and a snapshot that fails
     * for those is a snapshot a team learns to re-record without reading.
     * What is here is what a client is handed: names, descriptions, input and
     * output schemas, hints. Sorted, because discovery order is not contract.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mcpContract(): array
    {
        $contract = [];

        foreach ($this->mcpTools()->definitions as $definition) {
            $tool = app(GeneratedTool::class, ['definition' => $definition]);

            /** @var array<string, mixed> $visible */
            $visible = Arr::only(
                $tool->toArray(),
                ['description', 'inputSchema', 'outputSchema', 'annotations'],
            );

            $contract[$definition->name] = $visible;
        }

        ksort($contract);

        return $contract;
    }

    public function mcpTool(string $name): ToolDefinition
    {
        $result = $this->mcpTools();

        foreach ($result->definitions as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        Assert::fail("No tool named [{$name}] was compiled. Got: ".implode(', ', $result->names()));
    }

    /**
     * Run what the application deferred past the response.
     *
     * The audit is written after a response, which there is no response for
     * when a test calls a tool directly. Without this, an assertion about the
     * trail would be asserting that nothing had flushed yet, which is true
     * and useless.
     */
    protected function flushDeferred(): void
    {
        app(DeferredCallbackCollection::class)->invoke();
    }

    /**
     * Call a tool as an agent would.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function callMcpTool(
        string $name,
        array $arguments = [],
        ?Authenticatable $as = null,
        ?string $confirm = null,
        ?string $idempotencyKey = null,
    ): ToolResult {
        $actor = $as ?? auth()->user();

        if ($actor === null) {
            Assert::fail(
                "[{$name}] needs someone to call it as. Pass a user, or use actingAs() first: ".
                'a tool that cannot name who is calling does not run.',
            );
        }

        $result = app(ToolRunner::class)->run(new ToolCall(
            definition: $this->mcpTool($name),
            arguments: $arguments,
            actor: $actor,
            requestId: (string) Str::uuid(),
            clientName: 'tests',
            confirm: $confirm,
            idempotencyKey: $idempotencyKey,
        ));

        // A direct call ends no request, so what the application deferred has
        // to be run here for a test to be able to assert on it.
        $this->flushDeferred();

        return $result;
    }

    /**
     * Call a destructive tool through its confirmation handshake, which is
     * what an agent does: ask once, show the user, call again with the handle.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function confirmMcpTool(string $name, array $arguments = [], ?Authenticatable $as = null): ToolResult
    {
        $preview = $this->callMcpTool($name, $arguments, $as);
        $confirmation = $preview->structured['confirmation'] ?? null;
        $handle = is_array($confirmation) ? ($confirmation['handle'] ?? null) : null;

        // A tool that did not ask for confirmation has already run, and
        // calling it twice would do the work twice.
        if (! is_string($handle)) {
            return $preview;
        }

        return $this->callMcpTool($name, $arguments, $as, confirm: $handle);
    }

    public function assertToolExists(string $name): static
    {
        Assert::assertContains(
            $name,
            $this->mcpTools()->names(),
            "The tool [{$name}] is not exposed.",
        );

        return $this;
    }

    public function assertToolMissing(string $name): static
    {
        Assert::assertNotContains(
            $name,
            $this->mcpTools()->names(),
            "The tool [{$name}] is exposed, and this test expected it not to be.",
        );

        return $this;
    }

    /**
     * Assert that a candidate was found and deliberately not exposed, which is
     * a different thing from never having been discovered at all.
     */
    public function assertToolRefused(string $candidateId, ?string $code = null): static
    {
        $refusals = $this->mcpTools()->refusals;

        Assert::assertArrayHasKey(
            $candidateId,
            $refusals,
            "[{$candidateId}] was not refused. Refused: ".implode(', ', array_keys($refusals)),
        );

        if ($code !== null) {
            Assert::assertContains(
                $code,
                array_map(fn ($refusal): string => $refusal->code, $refusals[$candidateId]),
                "[{$candidateId}] was refused, but not with [{$code}].",
            );
        }

        return $this;
    }

    /**
     * Assert the agent-facing inputs of a tool, by path.
     *
     * @param  list<string>  $inputs
     */
    public function assertToolInputs(string $name, array $inputs): static
    {
        $schema = $this->mcpTool($name)->input;

        foreach ($inputs as $path) {
            Assert::assertNotNull(
                $schema->get($path),
                "The tool [{$name}] has no input [{$path}]. Got: ".implode(', ', array_keys($schema->fields)),
            );
        }

        return $this;
    }

    /**
     * Assert that a list tool offers the filters and sorts the endpoint
     * allows. Fails CI when a controller gains a filter and the agent schema
     * does not, which is the drift this is for.
     *
     * @param  list<string>  $filters
     * @param  list<string>  $sorts
     * @param  list<string>  $includes
     */
    public function assertQueryInputs(string $name, array $filters = [], array $sorts = [], array $includes = []): static
    {
        $definition = $this->mcpTool($name);
        $schema = $definition->input;

        foreach ($filters as $filter) {
            Assert::assertNotNull(
                $schema->get("filter.{$filter}"),
                "The tool [{$name}] does not offer the filter [{$filter}].",
            );
        }

        foreach (['sort' => $sorts, 'include' => $includes] as $path => $expected) {
            if ($expected === []) {
                continue;
            }

            $allowed = $schema->get("{$path}.*")->enum ?? [];

            foreach ($expected as $value) {
                Assert::assertContains(
                    $value,
                    $allowed,
                    "The tool [{$name}] does not allow [{$value}] in [{$path}]. Allowed: ".implode(', ', $allowed),
                );
            }
        }

        return $this;
    }

    public function assertToolReadOnly(string $name): static
    {
        Assert::assertTrue(
            $this->mcpTool($name)->safety->readOnly,
            "The tool [{$name}] is not read-only, so a client may treat it as a change.",
        );

        return $this;
    }

    public function assertToolNeedsConfirmation(string $name): static
    {
        Assert::assertTrue(
            $this->mcpTool($name)->authority->confirm,
            "The tool [{$name}] runs without asking, and this test expected it to confirm first.",
        );

        return $this;
    }

    /** @param list<string> $scopes */
    public function assertToolRequiresScopes(string $name, array $scopes): static
    {
        $declared = $this->mcpTool($name)->authority->scopes;

        foreach ($scopes as $scope) {
            Assert::assertContains(
                $scope,
                $declared,
                "The tool [{$name}] does not require the scope [{$scope}]. Requires: ".implode(', ', $declared),
            );
        }

        return $this;
    }
}
