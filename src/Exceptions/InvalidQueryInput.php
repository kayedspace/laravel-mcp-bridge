<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An agent asked for a filter, sort, include or field the endpoint does not
 * allow, and Spatie is not installed to say so itself.
 *
 * The message names the allowed values, in the same shape Spatie uses, because
 * an error an agent can act on is one that lists what would have worked.
 */
class InvalidQueryInput extends HttpException
{
    /**
     * @param  list<string>  $unknown
     * @param  list<string>  $allowed
     */
    public static function for(string $kind, array $unknown, array $allowed): self
    {
        $message = "Requested {$kind}(s) `".implode(', ', $unknown).'` are not allowed. '
            .($allowed === []
                ? "There are no allowed {$kind}s."
                : "Allowed {$kind}(s) are `".implode(', ', $allowed).'`.');

        return new self(400, $message);
    }
}
