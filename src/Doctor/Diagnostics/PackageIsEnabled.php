<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

/** Disabled on purpose is a state, not a fault: a warning so it is seen, never a failure. */
class PackageIsEnabled extends BridgeDiagnostic
{
    public string $name = 'MCP Bridge is enabled';

    public function check(): DiagnosticResult
    {
        $check = $this->checkup->enabled();

        return $check->ok
            ? DiagnosticResult::pass('MCP Bridge is enabled.')
            : DiagnosticResult::warn('MCP Bridge is disabled, so no server answers.')->suggest($check->detail);
    }
}
