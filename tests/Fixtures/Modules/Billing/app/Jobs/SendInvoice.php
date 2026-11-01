<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Modules\Billing\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'Send an invoice.')]
#[McpScope('billing:write')]
#[McpAbility('send-invoices')]
class SendInvoice implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public int $invoiceId) {}

    public function handle(): void
    {
        //
    }
}
