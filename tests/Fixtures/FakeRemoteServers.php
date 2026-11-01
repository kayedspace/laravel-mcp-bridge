<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use KayedSpace\McpBridge\Contracts\ConnectsRemoteServers;
use RuntimeException;

/** A remote MCP server, without the network. */
class FakeRemoteServers implements ConnectsRemoteServers
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(protected bool $unreachable = false) {}

    /** @return list<string> */
    public function servers(): array
    {
        return ['billing'];
    }

    /** @return list<array<string, mixed>> */
    public function tools(string $server): array
    {
        $this->reachable();

        return [
            [
                'name' => 'list_invoices',
                'title' => 'List invoices',
                'description' => 'Every invoice for a customer',
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['customer'],
                    'properties' => [
                        'customer' => ['type' => 'integer', 'description' => 'Whose invoices'],
                        'status' => ['type' => 'string', 'enum' => ['open', 'paid']],
                        'filter' => [
                            'type' => 'object',
                            'properties' => ['since' => ['type' => 'string', 'format' => 'date']],
                        ],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
            [
                'name' => 'void_invoice',
                'title' => null,
                'description' => null,
                'annotations' => [],
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{content: list<array<string, mixed>>, isError: bool, structuredContent: array<string, mixed>|null}
     */
    public function call(string $server, string $tool, array $arguments): array
    {
        $this->reachable();

        $this->calls[] = [$server, $tool, $arguments];

        if ($tool === 'void_invoice') {
            return [
                'content' => [['type' => 'text', 'text' => 'That invoice is already void.']],
                'isError' => true,
                'structuredContent' => null,
            ];
        }

        return [
            'content' => [['type' => 'text', 'text' => 'One invoice.']],
            'isError' => false,
            'structuredContent' => ['invoices' => [['id' => 1]]],
        ];
    }

    protected function reachable(): void
    {
        if ($this->unreachable) {
            throw new RuntimeException('Connection refused.');
        }
    }
}
