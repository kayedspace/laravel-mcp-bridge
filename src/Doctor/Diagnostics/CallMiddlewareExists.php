<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

class CallMiddlewareExists extends BridgeDiagnostic
{
    public string $name = 'MCP call middleware exists';

    public function check(): DiagnosticResult
    {
        $checks = $this->checkup->middleware();

        return $checks === []
            ? DiagnosticResult::skip('No call middleware is configured.')
            : $this->outcome($checks, 'Every class in execution.middleware exists and has a handle() method.');
    }
}
