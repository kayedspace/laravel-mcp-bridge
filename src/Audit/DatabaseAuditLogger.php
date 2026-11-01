<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Audit;

use Illuminate\Support\Facades\Date;
use KayedSpace\McpBridge\Contracts\StoresAudit;
use KayedSpace\McpBridge\Data\AuditEntry;
use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Server\ServerKeyResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The audit trail in a table of its own.
 *
 * Written after the response, not during it. An insert on the way out of
 * every call is latency an agent pays for a row it never reads, and the row
 * is as useful a moment later. `always` is set, because the entry that
 * matters most is the one for a call that failed or was refused.
 *
 * A failure to write the audit is logged and swallowed. That is a deliberate
 * choice and the uncomfortable one: an agent's call failing because the audit
 * table is missing would turn a reporting problem into an outage, and the
 * tool call has already happened by the time this runs, so refusing now would
 * record nothing and still have done the work.
 */
class DatabaseAuditLogger implements StoresAudit
{
    public function __construct(protected LoggerInterface $logger) {}

    public function record(AuditEntry $entry): void
    {
        // Captured now, written later: `created_at` is when the call
        // happened, not when the row was flushed.
        $row = [...$entry->toArray(), 'created_at' => Date::now()];

        // A stdio server is one long-lived process for the whole session, so
        // there is no response to defer past: deferred rows would queue until
        // the process ended, be lost the moment a client disconnected and it
        // was killed, and grow memory in the meantime. Written now instead.
        // HTTP keeps deferring, Octane included, because there a response is
        // exactly what the row should come after.
        if (ServerKeyResolver::servingLocally()) {
            $this->write($entry, $row);

            return;
        }

        defer(function () use ($entry, $row): void {
            $this->write($entry, $row);
        }, always: true);
    }

    /** @param array<string, mixed> $row */
    protected function write(AuditEntry $entry, array $row): void
    {
        try {
            McpAuditEntry::query()->create($row);
        } catch (Throwable $exception) {
            $this->logger->warning('mcp-bridge could not write an audit entry.', [
                'tool' => $entry->tool,
                'outcome' => $entry->outcome->value,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
