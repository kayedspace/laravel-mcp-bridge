<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

class GuardsAreDefined extends BridgeDiagnostic
{
    public string $name = 'MCP server guards are defined';

    public function check(): DiagnosticResult
    {
        return $this->outcome($this->checkup->guards(), 'Every guard an MCP server names is defined.');
    }
}
