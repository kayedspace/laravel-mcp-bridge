<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\OpenApiTarget;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\OpenApi\OpenApiDocuments;
use Throwable;

/**
 * Calls an OpenAPI operation over HTTP with this application's credential,
 * the way the remote executor reaches another MCP server: the agent's own
 * identity is never forwarded, because the API has never heard of it, and
 * the token the API is reached with never leaves this server.
 */
class OpenApiExecutor implements ExecutesTools
{
    public function __construct(
        protected Http $http,
        protected OpenApiDocuments $documents,
        protected WireSerializer $wire,
    ) {}

    public function execute(ToolCall $call): SourceResult
    {
        $target = $call->definition->target;

        if (! $target instanceof OpenApiTarget) {
            return SourceResult::failed(500, ['message' => 'This tool is not backed by an OpenAPI operation.']);
        }

        $settings = $this->documents->settings($target->document);
        $base = $settings['base_url'] ?? $this->documents->load($target->document)?->baseUrl();

        if (! is_string($base) || $base === '') {
            return SourceResult::failed(500, [
                'message' => "The [{$target->document}] API has no base URL: set base_url, or a server in the document.",
            ], null, ['code' => 'MCP_BRIDGE_SERVER_ERROR']);
        }

        $url = rtrim($base, '/').'/'.ltrim($this->wire->path($target->path, $call), '/');
        $body = $this->wire->body($call);

        $options = ['query' => $this->wire->query($call)];

        if ($body !== []) {
            $options['json'] = $body;
        }

        try {
            return $this->result($this->request($settings, $call)->send($target->method, $url, $options));
        } catch (ConnectionException $exception) {
            return SourceResult::failed(502, [
                'message' => "The [{$target->document}] API could not be reached.",
            ], $exception, ['code' => 'MCP_BRIDGE_REMOTE_UNAVAILABLE']);
        } catch (Throwable $exception) {
            return SourceResult::failed(500, null, $exception);
        }
    }

    /** @param array<string, mixed> $settings */
    protected function request(array $settings, ToolCall $call): PendingRequest
    {
        $timeout = $settings['timeout'] ?? null;

        $request = $this->http
            ->acceptJson()
            ->timeout(is_int($timeout) ? $timeout : ($call->definition->limits->timeout ?? 30))
            ->withHeaders([
                'X-Request-Id' => $call->requestId,
                'X-Mcp-Tool' => $call->definition->name,
            ]);

        $headers = $settings['headers'] ?? null;

        if (is_array($headers)) {
            $request = $request->withHeaders(array_filter($headers, 'is_string'));
        }

        $token = $settings['token'] ?? null;

        return is_string($token) && $token !== '' ? $request->withToken($token) : $request;
    }

    /**
     * The response as a source result: the status as it came, JSON as the
     * payload, and anything that is not JSON refused the way a route that
     * answers HTML is, since an agent cannot act on it.
     */
    protected function result(Response $response): SourceResult
    {
        $status = $response->status();
        $content = $response->body();

        if ($content === '') {
            return new SourceResult(status: $status, payload: null);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return new SourceResult(
                status: $status < 400 ? 500 : $status,
                payload: ['message' => 'The API did not return JSON.'],
                meta: ['code' => 'MCP_BRIDGE_NON_JSON'],
            );
        }

        return new SourceResult(
            status: $status,
            payload: $decoded,
            meta: array_filter(['retry_after' => $response->header('Retry-After') ?: null]),
        );
    }
}
