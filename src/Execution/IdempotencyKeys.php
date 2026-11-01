<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as Cache;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolResult;
use KayedSpace\McpBridge\Support\Duration;
use KayedSpace\McpBridge\Support\Fingerprint;
use Throwable;

/**
 * An agent's own key for a write, so a retry does not do the work twice.
 *
 * A dropped connection is the ordinary case, not the adversarial one. The
 * agent cannot tell a lost response from a call that never arrived, so it
 * retries, and a second enrolment or a second charge is the result. A single
 * use confirmation handle already covers destructive tools; this covers the
 * writes that create something, where the damage is a duplicate rather than
 * a deletion.
 *
 * The first call records the key and then its result. A repeat gets the
 * recorded result rather than a second execution, so the agent sees what it
 * would have seen had the first response arrived.
 */
class IdempotencyKeys
{
    public const INPUT = 'idempotency_key';

    protected const PREFIX = 'mcp-bridge:idempotent:';

    public function __construct(
        protected Cache $cache,
        protected Repository $config,
    ) {}

    public function enabled(): bool
    {
        return $this->config->boolean('mcp-bridge.execution.idempotency.enabled', true);
    }

    /**
     * Claim the key for this call, or hand back what the first call answered.
     *
     * `add` writes only when the key is absent and reports whether it did, so
     * of two calls racing with one key exactly one proceeds.
     *
     * Called only after the authority gate has agreed, so what is handed back
     * is an answer this caller is still allowed to have. Keying on the actor
     * rather than the token is sound for that reason and only for it: the
     * scopes are re-checked on every call, replays included.
     *
     * Returns null when the caller should go ahead. Fails open rather than
     * closed, unlike a confirmation handle: a cache that cannot answer means
     * a write runs twice at worst, while refusing would mean an agent cannot
     * write at all, and declining to act is not the safer outcome for an
     * operation whose point is to happen once.
     */
    public function claim(ToolCall $call): ?ToolResult
    {
        $key = $this->key($call);

        if ($key === null) {
            return null;
        }

        try {
            // The claim lives only as long as the tool may run. A first call
            // that died before recording anything would otherwise hold the
            // key for the whole result lifetime, and the agent would be told
            // for a day that a call nobody is running is still in flight.
            if ($this->cache->add($key, self::INPUT, $this->inFlightTtl($call))) {
                return null;
            }

            $recorded = $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }

        if ($recorded instanceof ToolResult) {
            // Marked, because an agent that retried should be able to tell it
            // was answered from the record rather than served twice, and an
            // audit row for a replay should not read like a second write.
            return new ToolResult(
                text: $recorded->text,
                structured: $recorded->structured,
                isError: $recorded->isError,
                errorCode: $recorded->errorCode,
                meta: [...$recorded->meta, 'replayed' => true],
            );
        }

        // Claimed but not yet finished: the first call is still running, or
        // it died before recording anything. Saying so is better than running
        // the write a second time while the first may still land.
        return ToolResult::error(
            'MCP_BRIDGE_IN_FLIGHT',
            'A call with this idempotency key is already running. Wait for it, or use a new key.',
        );
    }

    /**
     * Record what the call answered, so a retry can be given the same thing.
     *
     * Only a success is kept. A failure is not an answer worth repeating: an
     * agent that corrects a validation error and retries with the same key
     * must get the corrected call run, not the old refusal replayed, so the
     * claim is released instead.
     */
    public function record(ToolCall $call, ToolResult $result): void
    {
        $key = $this->key($call);

        if ($key === null) {
            return;
        }

        try {
            $result->isError
                ? $this->cache->forget($key)
                : $this->cache->put($key, $result, $this->ttl());
        } catch (Throwable) {
            // The work is done; failing to remember it is not worth failing
            // the call the agent is waiting on.
        }
    }

    /**
     * Keys are per tool, per caller and per set of arguments, so one agent's
     * key cannot collide with another's, the same key on a different tool is
     * a different operation, and a key reused with different arguments is a
     * different call rather than a replay of the first. The confirmation
     * handle fingerprints its arguments the same way, for the same reason.
     */
    protected function key(ToolCall $call): ?string
    {
        $supplied = $call->idempotencyKey;

        if (! $this->enabled() || $supplied === null || $supplied === '') {
            return null;
        }

        $actor = $call->actor->getAuthIdentifier();

        return self::PREFIX.hash('sha256', implode('|', [
            $call->definition->name,
            is_scalar($actor) ? (string) $actor : '',
            $supplied,
            $this->fingerprint($call),
        ]));
    }

    protected function fingerprint(ToolCall $call): string
    {
        // The arguments the executor will see, so a retry that adds or drops
        // a hidden field the executor discards anyway is still the same call.
        return Fingerprint::of($call->visibleArguments());
    }

    protected function ttl(): int
    {
        return Duration::seconds($this->config->get('mcp-bridge.execution.idempotency.ttl'), 86400);
    }

    protected function inFlightTtl(ToolCall $call): int
    {
        return max($call->definition->limits->timeout, 1);
    }
}
