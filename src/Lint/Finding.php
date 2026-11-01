<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Lint;

/**
 * One thing the lint has to say about what this application exposes.
 *
 * A finding is developer-facing and never reaches an agent. The level decides
 * whether it fails CI: an error is something that will not work as declared, a
 * warning is something an agent will handle worse than it could, and a note is
 * a deliberate choice recorded so it is visible.
 */
final readonly class Finding
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const NOTE = 'note';

    public function __construct(
        public string $level,
        public string $code,
        public string $subject,
        public string $message,
        public ?string $hint = null,
    ) {}

    public static function error(string $code, string $subject, string $message, ?string $hint = null): self
    {
        return new self(self::ERROR, $code, $subject, $message, $hint);
    }

    public static function warning(string $code, string $subject, string $message, ?string $hint = null): self
    {
        return new self(self::WARNING, $code, $subject, $message, $hint);
    }

    public static function note(string $code, string $subject, string $message, ?string $hint = null): self
    {
        return new self(self::NOTE, $code, $subject, $message, $hint);
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    /** @return array{level: string, code: string, subject: string, message: string, hint: string|null} */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'subject' => $this->subject,
            'message' => $this->message,
            'hint' => $this->hint,
        ];
    }
}
