<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Name the FormRequest whose rules describe this tool's input, for actions
 * that validate inline or type-hint a plain Request.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class McpInput
{
    /** @param class-string $request */
    public function __construct(public string $request) {}
}
