<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Server\Concerns;

use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Request;

/**
 * Which client is calling, for the audit trail.
 *
 * The stateless protocol carries the client's identity on every request,
 * in the `_meta` laravel/mcp hands over as the request's meta. One reader
 * for the two tools that write a trail, so they cannot name the key
 * differently.
 */
trait NamesClient
{
    protected function clientName(Request $request): ?string
    {
        $info = $request->meta()[MetaKey::CLIENT_INFO->value] ?? null;
        $name = is_array($info) ? ($info['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
