<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Exceptions;

use RuntimeException;

/**
 * A queued job whose authority ran out before it ran.
 *
 * An exception rather than a silent delete, so it lands in `failed_jobs` with
 * a reason: "an agent queued this and then lost the right to it" is exactly
 * the thing someone should see.
 */
class JobNoLongerAuthorized extends RuntimeException
{
    public static function actorGone(string $tool, int|string $actorId): self
    {
        return new self(
            "[{$tool}] was queued by actor [{$actorId}], who no longer exists, so it was not run.",
        );
    }

    public static function abilityLost(string $tool, string $ability): self
    {
        return new self(
            "[{$tool}] was queued by an actor who can no longer [{$ability}], so it was not run.",
        );
    }
}
