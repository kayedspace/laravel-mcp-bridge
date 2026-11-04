<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * Why a candidate may not be exposed. Refused candidates stay known so that
 * lint can explain them, instead of disappearing from discovery silently.
 */
final readonly class Refusal
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}

    public static function operationNotEnabled(string $id, string $operation): self
    {
        return new self(
            'MCP_BRIDGE_SOURCE_REFUSED',
            "The model asks for the [{$operation}] operation, but sources.model.operations does not enable it. "
                .'An attribute narrows that list and never widens it; add the operation there.',
        );
    }

    public static function deniedCommand(string $command): self
    {
        return new self('MCP_BRIDGE_SOURCE_REFUSED', "The command [{$command}] is on the deny list.");
    }

    public static function outsideNamespaces(string $command): self
    {
        return new self(
            'MCP_BRIDGE_SOURCE_REFUSED',
            "The command [{$command}] is outside the namespaces this application allows for tools.",
        );
    }

    /** @param list<string> $reasons */
    public static function unbuildable(string $id, array $reasons): self
    {
        return new self(
            'MCP_BRIDGE_SOURCE_REFUSED',
            "[{$id}] cannot be built from an agent's arguments: ".implode('; ', $reasons).'.',
        );
    }

    public static function policySilent(string $id, string $policy, string $ability): self
    {
        return new self(
            'MCP_BRIDGE_SOURCE_REFUSED',
            "[{$id}] needs [{$policy}::{$ability}()]; a policy that does not answer for an operation did not mean to allow it.",
        );
    }

    public static function missingAuthority(string $id): self
    {
        return new self(
            'MCP_BRIDGE_SOURCE_REFUSED',
            "[{$id}] needs both #[McpScope] and #[McpAbility] because it is not an HTTP route.",
        );
    }

    public static function missingPolicy(string $model): self
    {
        return new self('MCP_BRIDGE_SOURCE_REFUSED', "The model [{$model}] has no registered policy.");
    }

    /**
     * A later source wanting a name an earlier one holds. The earlier claim
     * stands, because source order puts the route first and a route carries
     * authority the others only declare, and the later candidate is told
     * how to take a name of its own.
     */
    public static function nameTaken(string $id, string $name, string $holder): self
    {
        return new self(
            'MCP_BRIDGE_NAME_TAKEN',
            "[{$id}] compiles to [{$name}], which [{$holder}] already claims. Give it #[McpTool(name: ...)], or a route override, to expose both.",
        );
    }

    /**
     * A rate limit that cannot be read. Refused rather than served, because
     * served it would run with no limit at all, and the developer who wrote
     * one meant the opposite.
     */
    public static function invalidRateLimit(string $id, string $rateLimit, string $setting): self
    {
        return new self(
            'MCP_BRIDGE_INVALID_RATE_LIMIT',
            "[{$id}] has the rate limit [{$rateLimit}] from [{$setting}], which is not one. "
                .'Write N/second, N/minute or N/hour, or a window such as N/30seconds or N/5minutes.',
        );
    }

    public static function destructiveDenied(string $id): self
    {
        return new self(
            'MCP_BRIDGE_NOT_EXPOSED',
            "[{$id}] is destructive, and [security.destructive] is set to deny.",
        );
    }

    public static function ignored(string $id): self
    {
        return new self('MCP_BRIDGE_IGNORED', "[{$id}] is marked #[McpIgnore].");
    }

    public static function notExposed(string $id, string $mode): self
    {
        return new self(
            'MCP_BRIDGE_NOT_EXPOSED',
            "[{$id}] is not exposed in [{$mode}] mode. Add #[McpTool] to opt it in.",
        );
    }

    /** @return array{code: string, message: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}
