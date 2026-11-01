<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Audit;

use KayedSpace\McpBridge\Contracts\StoresAudit;
use KayedSpace\McpBridge\Data\AuditEntry;
use Psr\Log\LoggerInterface;

/**
 * The audit trail as log lines, for an application that already ships its logs
 * somewhere it can query them.
 */
class LogAuditLogger implements StoresAudit
{
    public function __construct(protected LoggerInterface $logger) {}

    public function record(AuditEntry $entry): void
    {
        $this->logger->info("mcp-bridge {$entry->outcome->value}: {$entry->tool}", $entry->toArray());
    }
}
