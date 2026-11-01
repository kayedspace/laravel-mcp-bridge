<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

class RateLimitersAreDefined extends BridgeDiagnostic
{
    public string $name = 'MCP rate limiters are defined';

    public function check(): DiagnosticResult
    {
        return $this->outcome($this->checkup->limiters(), 'Every rate limiter an MCP server names is defined.');
    }
}
