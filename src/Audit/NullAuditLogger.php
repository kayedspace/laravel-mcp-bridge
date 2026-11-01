<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Audit;

use KayedSpace\McpBridge\Contracts\StoresAudit;
use KayedSpace\McpBridge\Data\AuditEntry;

/** No audit trail, for an application that has decided it does not want one. */
class NullAuditLogger implements StoresAudit
{
    public function record(AuditEntry $entry): void
    {
        //
    }
}
