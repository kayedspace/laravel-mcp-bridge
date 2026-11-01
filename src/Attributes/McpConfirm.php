<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Ask the user before this runs.
 *
 * The protocol is stateless, so confirmation is a handle the agent passes
 * back on a second call rather than a question the server asks mid-call.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class McpConfirm
{
    public function __construct(public ?string $message = null) {}
}
