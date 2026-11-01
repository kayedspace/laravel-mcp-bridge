<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Contracts;

use KayedSpace\McpBridge\Data\AuditEntry;

/**
 * Where the record of what agents did is kept. A database table by default,
 * a log channel for an application that ships its logs somewhere else, and
 * replaceable for anywhere a team already sends its audit trail.
 */
interface StoresAudit
{
    public function record(AuditEntry $entry): void;
}
