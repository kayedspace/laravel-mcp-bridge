<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * Where a tool's code lives. The only part of a definition that knows how to
 * reach the underlying route, command or class.
 *
 * Reading a target back from storage belongs to {@see Targets}, so that the
 * one place which maps a stored type to a class stays the one place.
 *
 * @phpstan-import-type TargetArray from Targets
 */
interface Target
{
    public function type(): string;

    public function describe(): string;

    /** @return TargetArray */
    public function toArray(): array;
}
