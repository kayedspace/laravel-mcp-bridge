<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use Laravel\Doctor\Results\DiagnosticResult;

class ManifestIsCurrent extends BridgeDiagnostic
{
    public string $name = 'MCP manifest is current';

    public function check(): DiagnosticResult
    {
        $check = $this->checkup->manifest();

        return $check->ok
            ? DiagnosticResult::pass('The tool manifest is '.$check->detail.'.')
            : DiagnosticResult::fail('The tool manifest is '.$check->detail.'.')->suggest('Run php artisan mcp-bridge:cache, or php artisan optimize, in the deploy.');
    }
}
