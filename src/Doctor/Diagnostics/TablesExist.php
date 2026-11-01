<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

class TablesExist extends BridgeDiagnostic
{
    public string $name = 'MCP tables exist';

    public function check(): DiagnosticResult
    {
        return $this->outcome($this->checkup->tables(), 'The tokens, audit and kills tables exist.');
    }
}
