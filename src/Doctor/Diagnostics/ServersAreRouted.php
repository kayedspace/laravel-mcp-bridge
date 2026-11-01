<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

class ServersAreRouted extends BridgeDiagnostic
{
    public string $name = 'MCP servers are routed';

    public function check(): DiagnosticResult
    {
        return $this->outcome($this->checkup->servers(), 'Every configured MCP server has a path and a route.');
    }
}
