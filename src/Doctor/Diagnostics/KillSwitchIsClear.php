<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

/** Something switched off is a state to know about, not a fault: a notice, never a failure. */
class KillSwitchIsClear extends BridgeDiagnostic
{
    public string $name = 'MCP kill switch is clear';

    public function check(): DiagnosticResult
    {
        $check = $this->checkup->killSwitch();

        return $check->ok
            ? DiagnosticResult::pass('Nothing is switched off.')
            : DiagnosticResult::notice('Switched '.$check->detail.'.')->suggest('Revive with php artisan mcp-bridge:kill --revive when the incident is over.');
    }
}
