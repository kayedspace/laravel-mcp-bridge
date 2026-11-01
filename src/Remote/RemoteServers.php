<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Remote;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Contracts\ConnectsRemoteServers;
use Laravel\Mcp\Client;
use Laravel\Mcp\WebClient;
use RuntimeException;

/**
 * The configured remote servers, through laravel/mcp's own client.
 *
 * Clients are kept for the length of the process, because discovery lists the
 * tools and a call then uses the same connection, and reconnecting per call
 * would pay the handshake twice for no reason.
 */
class RemoteServers implements ConnectsRemoteServers
{
    /** @var array<string, WebClient> */
    protected array $clients = [];

    public function __construct(protected Repository $config) {}

    /** @return list<string> */
    public function servers(): array
    {
        $servers = [];

        foreach ($this->config->array('mcp-bridge.sources.remote.servers', []) as $key => $server) {
            if (is_string($key) && is_array($server) && is_string($server['url'] ?? null)) {
                $servers[] = $key;
            }
        }

        return $servers;
    }

    /** @return list<array<string, mixed>> */
    public function tools(string $server): array
    {
        $tools = [];

        foreach ($this->client($server)->tools() as $tool) {
            $tools[] = [
                'name' => $tool->name,
                'title' => $tool->title,
                'description' => $tool->description,
                'inputSchema' => $tool->inputSchema,
                'annotations' => $tool->annotations,
            ];
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{content: list<array<string, mixed>>, isError: bool, structuredContent: array<string, mixed>|null}
     */
    public function call(string $server, string $tool, array $arguments): array
    {
        $result = $this->client($server)->callTool($tool, $arguments);

        return [
            'content' => array_values($result->content),
            'isError' => $result->isError,
            'structuredContent' => $result->structuredContent,
        ];
    }

    /** @return array<string, string> */
    protected function headers(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $strings = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $strings[$name] = $value;
            }
        }

        return $strings;
    }

    protected function client(string $server): WebClient
    {
        if (isset($this->clients[$server])) {
            return $this->clients[$server];
        }

        $settings = $this->config->array("mcp-bridge.sources.remote.servers.{$server}", []);
        $url = $settings['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("The remote server [{$server}] has no url.");
        }

        $client = Client::web($url);
        $token = $settings['token'] ?? null;

        if (is_string($token) && $token !== '') {
            $client = $client->withToken($token);
        }

        $headers = $this->headers($settings['headers'] ?? null);

        if ($headers !== []) {
            $client = $client->withHeaders($headers);
        }

        $timeout = $settings['timeout'] ?? null;

        if (is_int($timeout) || is_float($timeout)) {
            $client = $client->withTimeout((float) $timeout);
        }

        return $this->clients[$server] = $client;
    }
}
