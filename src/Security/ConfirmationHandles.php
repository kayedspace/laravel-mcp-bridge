<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Security;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\StringEncrypter;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Support\Duration;
use KayedSpace\McpBridge\Support\Fingerprint;
use Throwable;

/**
 * Confirmation without a session.
 *
 * MCP revision 2026-07-28 removed the session and replaced server-initiated
 * requests with Multi Round-Trip Requests, which major clients do not
 * implement yet. So a destructive tool returns a handle that the agent passes
 * back on a second call. The handle is encrypted with the application key and
 * carries its own contents: it survives Octane workers and several app
 * servers, and changing any argument invalidates it.
 *
 * Spending one is the only thing written down, because being self-contained
 * would otherwise make it replayable for its whole lifetime, and an agent
 * that retries a dropped connection would delete twice.
 */
class ConfirmationHandles
{
    protected const SPENT = 'mcp-bridge:confirmed:';

    public function __construct(
        protected StringEncrypter $encrypter,
        protected Repository $config,
        protected Cache $cache,
    ) {}

    public function issue(ToolCall $call): string
    {
        return $this->encrypter->encryptString((string) json_encode([
            'tool' => $call->definition->name,
            'actor' => $this->actor($call),
            'arguments' => $this->fingerprint($call),
            'expires' => time() + $this->ttl(),
        ]));
    }

    /**
     * Check a handle and spend it, which is one step on purpose: anything
     * that checked without spending could be called twice and answer the
     * same both times, which is the bug this exists to prevent.
     */
    public function redeem(ToolCall $call): bool
    {
        $expires = $this->verify($call);

        if ($expires === null) {
            return false;
        }

        // `add` writes only when the key is absent and reports whether it
        // did, so two calls racing with one handle cannot both win it. Held
        // exactly as long as the handle itself could still be presented.
        //
        // Fails closed: a cache that cannot answer cannot promise single use,
        // and a destructive call is the wrong place to assume the best.
        try {
            return $this->cache->add(self::SPENT.hash('sha256', (string) $call->confirm), true, $expires - time());
        } catch (Throwable) {
            return false;
        }
    }

    /** The moment this handle stops being valid, or null if it never was. */
    protected function verify(ToolCall $call): ?int
    {
        if ($call->confirm === null || $call->confirm === '') {
            return null;
        }

        try {
            $payload = json_decode($this->encrypter->decryptString($call->confirm), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $expires = $payload['expires'] ?? null;

        // Exclusive, so a zero-second lifetime is never valid.
        if (! is_int($expires) || $expires <= time()) {
            return null;
        }

        $matches = ($payload['tool'] ?? null) === $call->definition->name
            && ($payload['actor'] ?? null) === $this->actor($call)
            && ($payload['arguments'] ?? null) === $this->fingerprint($call);

        return $matches ? $expires : null;
    }

    public function ttl(): int
    {
        return Duration::seconds($this->config->get('mcp-bridge.security.confirm_handle_ttl'), 300);
    }

    /**
     * The arguments a handle is bound to, with the handle itself excluded so
     * that confirming does not change the fingerprint it is checked against.
     */
    protected function fingerprint(ToolCall $call): string
    {
        // The arguments the executor will see, so a hidden field the
        // executor discards cannot change what was confirmed.
        $arguments = $call->visibleArguments();
        unset($arguments['confirm']);

        return Fingerprint::of($arguments);
    }

    protected function actor(ToolCall $call): string
    {
        $identifier = $call->actor->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : '';
    }
}
