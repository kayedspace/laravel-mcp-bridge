<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor\Diagnostics;

use KayedSpace\McpBridge\Doctor\Check;
use KayedSpace\McpBridge\Doctor\Checkup;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

/**
 * One of this package's checks, as a `laravel/doctor` diagnostic.
 *
 * The checks are the `Checkup`'s; this only turns a list of them into the
 * one result doctor prints per diagnostic, so an operator running
 * `php artisan doctor` sees this package's state beside the framework's
 * without learning a second command. Exists only when laravel/doctor is
 * installed, which is why the package suggests it rather than requiring it.
 */
abstract class BridgeDiagnostic extends Diagnostic
{
    public string $group = 'mcp-bridge';

    public function __construct(protected Checkup $checkup)
    {
        parent::__construct();
    }

    /**
     * Every check passed, or the first failure as the headline with the
     * rest as details. The failing check's detail is already the remedy,
     * written for a person, so it is the suggestion too.
     *
     * @param  list<Check>  $checks
     */
    protected function outcome(array $checks, string $passed): DiagnosticResult
    {
        $failed = array_values(array_filter($checks, fn (Check $check): bool => ! $check->ok));

        if ($failed === []) {
            return DiagnosticResult::pass($passed);
        }

        $result = DiagnosticResult::fail($failed[0]->label.': '.$failed[0]->detail.'.')
            ->suggest($failed[0]->detail);

        return count($failed) === 1
            ? $result
            : $result->withDetails(implode("\n", array_map(
                fn (Check $check): string => $check->label.': '.$check->detail,
                $failed,
            )));
    }
}
