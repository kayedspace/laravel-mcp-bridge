<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Doctor;

/**
 * One answer from the checkup: what was looked at, whether it is fine, and
 * what to do about it when it is not. The detail is written for the person
 * reading it, so the remedy is the detail.
 */
final readonly class Check
{
    public function __construct(
        public string $label,
        public bool $ok,
        public string $detail,
    ) {}
}
