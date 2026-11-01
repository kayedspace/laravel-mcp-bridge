<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use KayedSpace\McpBridge\Contracts\ConnectsRemoteServers;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\RemoteTarget;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use Throwable;

/**
 * Forwards a call to the server the tool actually lives on.
 *
 * The remote's answer is passed through rather than reinterpreted: it already
 * shaped its own result for an agent, and shaping it twice would mean deciding
 * what to drop from a payload this application does not understand.
 *
 * What is not forwarded is the caller's identity. The remote is reached with
 * this application's own credential, because the remote has never heard of
 * this agent and would have no way to check it.
 */
class RemoteExecutor implements ExecutesTools
{
    public function __construct(protected ConnectsRemoteServers $servers) {}

    public function execute(ToolCall $call): SourceResult
    {
        $target = $call->definition->target;

        if (! $target instanceof RemoteTarget) {
            return SourceResult::failed(500, ['message' => 'This tool is not backed by a remote server.']);
        }

        try {
            $result = $this->servers->call($target->server, $target->tool, $call->visibleArguments());
        } catch (Throwable $exception) {
            return SourceResult::failed(502, [
                'message' => "The [{$target->server}] server could not be reached.",
            ], $exception, ['code' => 'MCP_BRIDGE_REMOTE_UNAVAILABLE']);
        }

        if ($result['isError']) {
            return SourceResult::failed(500, [
                'message' => $this->text($result['content']) ?? 'The remote tool reported an error.',
            ], null, ['code' => 'MCP_BRIDGE_REMOTE_ERROR']);
        }

        return SourceResult::ok(
            $result['structuredContent'] ?? ['text' => $this->text($result['content'])],
            ['remote' => $target->server],
        );
    }

    /** @param list<array<string, mixed>> $content */
    protected function text(array $content): ?string
    {
        $parts = [];

        foreach ($content as $item) {
            $text = $item['text'] ?? null;

            if (($item['type'] ?? null) === 'text' && is_string($text)) {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }
}
