<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Enums;

/**
 * What became of a tool call: the gate stopped it, the source ran it, or the
 * source ran it and it went wrong. Nothing else can happen to one.
 */
enum Outcome: string
{
    case Allowed = 'allowed';

    case Refused = 'refused';

    case Failed = 'failed';
}
