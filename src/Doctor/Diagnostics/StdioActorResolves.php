<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

class StdioActorResolves extends BridgeDiagnostic
{
    public string $name = 'MCP stdio actor resolves';

    public function check(): DiagnosticResult
    {
        $check = $this->checkup->localActor();

        return $check === null
            ? DiagnosticResult::skip('Stdio serving is off.')
            : $this->outcome([$check], 'Stdio calls run as '.$check->detail.'.');
    }
}
