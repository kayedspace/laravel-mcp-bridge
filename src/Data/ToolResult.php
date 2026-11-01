<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/** What the agent receives, after shaping. */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $text,
        public ?array $structured = null,
        public bool $isError = false,
        public ?string $errorCode = null,
        public array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<string, mixed>  $meta
     */
    public static function ok(string $text, ?array $structured = null, array $meta = []): self
    {
        return new self(text: $text, structured: $structured, meta: $meta);
    }

    /** @param array<string, mixed>|null $structured */
    public static function error(string $code, string $text, ?array $structured = null): self
    {
        return new self(text: $text, structured: $structured, isError: true, errorCode: $code);
    }
}
