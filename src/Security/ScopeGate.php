<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Security;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use KayedSpace\McpBridge\Contracts\AuthorizesTools;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Data\ToolResult;

/**
 * Everything that must hold before a tool runs: token scopes, Gate abilities,
 * the per-tool rate limit, and confirmation for destructive work.
 *
 * Scopes and abilities are checked twice, once when listing so the tool is
 * hidden and once here, because hiding a tool is not enforcement.
 */
class ScopeGate implements AuthorizesTools
{
    /**
     * The gate an application defines to decide who may use its MCP servers
     * at all, such as only subscribers. Undefined, it decides nothing, so a
     * fresh install keeps working; defined, it is asked on every listing and
     * every call, with the server's key as its argument.
     */
    public const ACCESS = 'accessMcp';

    public function __construct(
        protected TokenScopes $scopes,
        protected Gate $gate,
        protected RateLimiter $limiter,
        protected ConfirmationHandles $handles,
        protected KillSwitch $kill,
    ) {}

    public function visible(ToolDefinition $definition, ?Authenticatable $actor): bool
    {
        return ! $this->kill->isKilled($definition)
            && ! $this->deniedAccess($definition, $actor)
            && $this->missingScope($definition, $actor) === null
            && $this->deniedAbility($definition, $actor) === null;
    }

    public function check(ToolCall $call): ?ToolResult
    {
        $definition = $call->definition;
        $actor = $call->actor;

        // A client that cached a tool list a minute ago must not be able to
        // call something that has since been switched off.
        if ($this->kill->isKilled($definition)) {
            return ToolResult::error(
                'MCP_BRIDGE_DISABLED',
                "The tool [{$definition->name}] is switched off. Do not retry.",
            );
        }

        if ($this->deniedAccess($definition, $actor)) {
            return ToolResult::error(
                'MCP_BRIDGE_ACCESS',
                'This account does not have access to these tools. Do not retry.',
            );
        }

        $scope = $this->missingScope($definition, $actor);

        if ($scope !== null) {
            return ToolResult::error('MCP_BRIDGE_SCOPE', $scope);
        }

        $ability = $this->deniedAbility($definition, $actor);

        if ($ability !== null) {
            return ToolResult::error('MCP_BRIDGE_FORBIDDEN', $ability);
        }

        if (($seconds = $this->rateLimited($call)) !== null) {
            return ToolResult::error(
                'MCP_BRIDGE_RATE_LIMITED',
                "This tool is rate limited. Retry after {$seconds} seconds.",
            );
        }

        return $this->confirmation($call);
    }

    /**
     * Confirmation is a two-step handshake, not an error: the first call
     * returns what would happen plus a handle, and the agent calls again with
     * it once the user has agreed.
     */
    protected function confirmation(ToolCall $call): ?ToolResult
    {
        if (! $call->definition->authority->confirm) {
            return null;
        }

        if ($this->handles->redeem($call)) {
            return null;
        }

        if ($call->confirm !== null && $call->confirm !== '') {
            return ToolResult::error(
                'MCP_BRIDGE_CONFIRMATION_EXPIRED',
                'That confirmation is no longer valid, because it was already used, it expired, '.
                'or the arguments changed. Call the tool again without it to get a fresh preview.',
            );
        }

        $handle = $this->handles->issue($call);

        return new ToolResult(
            text: $this->preview($call, $handle),
            structured: [
                'confirmation' => [
                    'required' => true,
                    'handle' => $handle,
                    'expires_in' => $this->handles->ttl(),
                ],
            ],
            isError: false,
            errorCode: 'MCP_BRIDGE_CONFIRMATION_REQUIRED',
        );
    }

    /**
     * The handle is in the text as well as the structured content, because a
     * client that reads only text would otherwise be told to send back
     * something it was never shown.
     */
    protected function preview(ToolCall $call, string $handle): string
    {
        $definition = $call->definition;

        $message = $definition->authority->confirmMessage
            ?? 'This will run ['.$definition->name.'] on '.$definition->target->describe().'.';

        $arguments = $call->arguments === []
            ? 'no arguments'
            : (string) json_encode($call->arguments);

        return $message.' Arguments: '.$arguments.'. '
            .'Ask the user to confirm, then call this tool again with this value as the [confirm] argument: '
            .$handle;
    }

    protected function deniedAccess(ToolDefinition $definition, ?Authenticatable $actor): bool
    {
        return $this->gate->has(self::ACCESS)
            && $this->gate->forUser($actor)->denies(self::ACCESS, [$definition->server]);
    }

    protected function missingScope(ToolDefinition $definition, ?Authenticatable $actor): ?string
    {
        return $this->scopes->refusalFor($definition->authority->scopes, $actor, 'tool');
    }

    protected function deniedAbility(ToolDefinition $definition, ?Authenticatable $actor): ?string
    {
        foreach ($definition->authority->abilities as $ability) {
            if (! $this->gate->forUser($actor)->allows($ability)) {
                return "Not allowed: this tool requires the [{$ability}] ability. Do not retry.";
            }
        }

        return null;
    }

    /** @return int|null seconds to wait, or null when the call may proceed */
    protected function rateLimited(ToolCall $call): ?int
    {
        $limit = $call->definition->limits->rateLimit;

        if ($limit === null || preg_match('/^(\d+)\/(\d*)\s*(second|minute|hour)s?$/', $limit, $matches) !== 1) {
            return null;
        }

        $attempts = (int) $matches[1];
        $every = $matches[2] === '' ? 1 : (int) $matches[2];

        $decay = $every * match ($matches[3]) {
            'second' => 1,
            'hour' => 3600,
            default => 60,
        };

        $identifier = $call->actor->getAuthIdentifier();
        $key = 'mcp-bridge:'.$call->definition->name.':'.(is_scalar($identifier) ? $identifier : 'unknown');

        if ($this->limiter->tooManyAttempts($key, $attempts)) {
            return $this->limiter->availableIn($key);
        }

        $this->limiter->hit($key, $decay);

        return null;
    }
}
