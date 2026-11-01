<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Modules\Billing\app\Http\Controllers;

use Illuminate\Http\JsonResponse;
use KayedSpace\McpBridge\Attributes\McpTool;

/** A controller that lives inside a module rather than in the application. */
class InvoiceController
{
    #[McpTool(description: 'List invoices.')]
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
