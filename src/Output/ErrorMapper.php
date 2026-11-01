<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Output;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Data\ToolResult;

/**
 * Turns a failed call into something an agent can act on: which field to fix,
 * whether to retry, where to find a valid id. A 5xx never carries internals.
 */
class ErrorMapper
{
    public function __construct(protected Repository $config) {}

    public function map(SourceResult $result, ToolDefinition $definition): ToolResult
    {
        $payload = is_array($result->payload) ? $result->payload : [];
        $message = is_string($payload['message'] ?? null) ? $payload['message'] : null;

        // An executor that already knows the error code says so, rather than
        // having it guessed back out of a status.
        $code = $result->meta['code'] ?? null;

        if (is_string($code)) {
            return ToolResult::error($code, $message ?? 'The call could not be completed.');
        }

        return match (true) {
            // A rejected filter, sort, include or field, from Spatie or from
            // this package's own applier, arrives as a 400 whose message
            // names the allowed values, which is what the agent needs. The
            // message is passed through for every 400 for the same reason.
            $result->status === 400 => ToolResult::error(
                'MCP_BRIDGE_BAD_REQUEST',
                $message ?? 'The route rejected the request.',
            ),
            $result->status === 422 => $this->validation($payload, $message),
            $result->status === 401 => ToolResult::error(
                'MCP_BRIDGE_UNAUTHENTICATED',
                'Not authenticated. The connection needs to be re-authorised.',
            ),
            $result->status === 403 => ToolResult::error(
                'MCP_BRIDGE_FORBIDDEN',
                $message ?? 'Not allowed for this user. Do not retry.',
            ),
            $result->status === 404 => ToolResult::error(
                'MCP_BRIDGE_NOT_FOUND',
                $this->notFound($definition, $message),
            ),
            $result->status === 429 => ToolResult::error(
                'MCP_BRIDGE_RATE_LIMITED',
                'Rate limited. Retry after '.$this->retryAfter($result).' seconds.',
            ),
            // Maintenance mode, now that the sub-request goes through the
            // kernel that enforces it. Not a failure of the call, and not
            // one to retry at once.
            $result->status === 503 => ToolResult::error(
                'MCP_BRIDGE_UNAVAILABLE',
                'The application is unavailable, usually for maintenance. Retry later.',
            ),
            $result->status >= 500 => ToolResult::error(
                'MCP_BRIDGE_SERVER_ERROR',
                $this->serverError($result, $message),
            ),
            default => ToolResult::error(
                'MCP_BRIDGE_SERVER_ERROR',
                $message ?? "The call failed with status {$result->status}.",
            ),
        };
    }

    /** @param array<array-key, mixed> $payload */
    protected function validation(array $payload, ?string $message): ToolResult
    {
        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];

        $lines = [];

        foreach ($errors as $field => $messages) {
            $lines[] = "{$field}: ".$this->flatten($messages);
        }

        return ToolResult::error(
            'MCP_BRIDGE_VALIDATION',
            trim(($message ?? 'The input was not valid.')."\n".implode("\n", $lines)),
            ['errors' => $errors],
        );
    }

    protected function flatten(mixed $messages): string
    {
        if (is_array($messages)) {
            return implode(' ', array_map(
                fn (mixed $message): string => is_scalar($message) ? (string) $message : '',
                $messages,
            ));
        }

        return is_scalar($messages) ? (string) $messages : '';
    }

    protected function retryAfter(SourceResult $result): string
    {
        $retryAfter = $result->meta['retry_after'] ?? null;

        return is_scalar($retryAfter) ? (string) $retryAfter : 'a few';
    }

    /**
     * A tool that takes a record's key is one a list tool can find a key
     * for, so it says so. Decided from the inputs rather than from the name,
     * which `naming.style` and `naming.pattern` are free to change.
     */
    protected function notFound(ToolDefinition $definition, ?string $message): string
    {
        return ($message ?? 'Not found.').($this->takesKey($definition) ? ' Use the matching list tool to find a valid id.' : '');
    }

    protected function takesKey(ToolDefinition $definition): bool
    {
        foreach ($definition->input->fields as $field) {
            if (array_intersect($field->origin, ['route-param', 'model:key']) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * A route's exception is rendered by the application's handler, which
     * says "Server Error" with debug off and the real message with it on, so
     * the detail comes from the rendered message when no exception reached
     * here. Either way it is only shown when the configuration asks.
     */
    protected function serverError(SourceResult $result, ?string $message): string
    {
        $detail = $result->exception?->getMessage()
            ?? ($message === 'Server Error' ? null : $message);

        $shown = $this->config->boolean('mcp-bridge.security.expose_error_details', false)
            && $detail !== null && $detail !== ''
                ? ' '.$detail
                : '';

        return 'The application failed to handle the call.'.$shown;
    }
}
