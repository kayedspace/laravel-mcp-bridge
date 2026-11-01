<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * How long a call may take, in seconds, overriding the per-source default.
 *
 * Advisory, because everything this attribute can sit on runs inside this
 * process: the budget tells the agent what to expect in the description,
 * bounds how long an idempotency key is held in flight, and interrupts
 * nothing; `mcp-bridge:lint` notes it. The one source that is cut off at a
 * budget, an OpenAPI document, has no code to carry an attribute and takes
 * its `timeout` from configuration. Cutting a running request off needs a
 * subprocess, which is a cost per call the sources that have a cheaper
 * answer, a queued job and `job_status`, do not pay.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class McpTimeout
{
    public function __construct(public int $seconds) {}
}
