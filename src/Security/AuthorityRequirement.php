<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Security;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Data\Authority;
use KayedSpace\McpBridge\Data\Declared;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\ToolCandidate;

/**
 * The rule that a source other than HTTP routes may only be exposed with
 * authority of its own.
 *
 * A route arrives wrapped in middleware, a guard and usually a policy, and a
 * tool call inherits all of it. A command, job, action or model declares no
 * protection, and its implicit identity is the server operator, so inheriting
 * that would be privilege escalation by design.
 *
 * It lives here rather than in the compiler because the lint has to report
 * exactly what the compiler refuses: one rule, one place, two readers.
 */
class AuthorityRequirement
{
    public function __construct(protected Repository $config) {}

    /** @return list<Refusal> */
    public function refusals(string $sourceKey, ToolCandidate $candidate): array
    {
        // Before the source check, because `deny` is not about where a tool
        // came from. A destructive route is the case it most needs to cover,
        // and a route is exactly what the source check waves through.
        if ($this->denied($candidate)) {
            return [Refusal::destructiveDenied($candidate->id)];
        }

        if (! $this->applies($sourceKey)) {
            return [];
        }

        return $this->authority($candidate)->isDeclared()
            ? []
            : [Refusal::missingAuthority($candidate->id)];
    }

    /**
     * `destructive => deny` means no agent gets a destructive tool here, so
     * the candidate is never exposed rather than exposed behind a handshake.
     *
     * The configuration has always offered this value and the code only ever
     * tested for `confirm`, so setting the strictest of the three produced
     * the weakest behaviour of the three: destructive tools exposed with no
     * confirmation at all.
     */
    protected function denied(ToolCandidate $candidate): bool
    {
        return $candidate->safety?->destructive === true
            && $this->config->string('mcp-bridge.security.destructive', 'confirm') === 'deny';
    }

    protected function applies(string $sourceKey): bool
    {
        return $sourceKey !== 'route'
            && $this->config->boolean('mcp-bridge.security.require_scope_for_non_http', true);
    }

    /**
     * What the declaration grants, plus the confirmation the configuration
     * asks for. A DELETE route is destructive by inference, and the global
     * setting decides whether that alone needs confirming; #[McpConfirm] asks
     * for it regardless.
     */
    public function authority(ToolCandidate $candidate): Authority
    {
        $declared = $candidate->declared ?? new Declared;

        $confirm = $declared->confirm || (
            $candidate->safety?->destructive === true
            && $this->config->string('mcp-bridge.security.destructive', 'confirm') === 'confirm'
        );

        return new Authority(
            scopes: $declared->scopes,
            abilities: $declared->abilities,
            confirm: $confirm,
            confirmMessage: $declared->confirmMessage,
        );
    }
}
