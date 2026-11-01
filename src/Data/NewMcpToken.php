<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

use KayedSpace\McpBridge\Models\McpToken;

/**
 * A token just issued: the stored record and the plain text, which exists
 * only here and is never kept.
 */
final readonly class NewMcpToken
{
    public function __construct(
        public McpToken $accessToken,
        public string $plainText,
    ) {}
}
