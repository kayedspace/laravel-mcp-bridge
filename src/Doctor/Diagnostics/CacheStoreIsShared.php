<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

class CacheStoreIsShared extends BridgeDiagnostic
{
    public string $name = 'MCP cache store is shared';

    public function check(): DiagnosticResult
    {
        return $this->outcome([$this->checkup->cache()], 'The cache store is one every worker shares, so confirmation handles and idempotency keys hold.');
    }
}
