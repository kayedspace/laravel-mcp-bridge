<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Contracts;

use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolCandidate;

/**
 * A kind of thing that can become a tool: HTTP routes, Artisan commands,
 * jobs, actions, models, app resources, remote servers.
 *
 * A source describes and runs. It never decides whether a call is allowed,
 * writes audit entries or shapes its own output: that belongs to the shared
 * pipeline, which is what keeps seven sources from becoming seven security
 * models.
 */
interface Source
{
    /** Stable key used in config, naming and audit: 'route', 'command', ... */
    public function key(): string;

    /**
     * Everything this source could expose, before filtering, in a
     * deterministic order.
     *
     * @return iterable<ToolCandidate>
     */
    public function discover(): iterable;

    /** The source's own view of the inputs. Other readers merge on top. */
    public function schema(ToolCandidate $candidate): InputSchema;

    /**
     * Compile-time refusals, such as a denied command or a model without a
     * policy. A refused candidate stays known so lint can explain it.
     *
     * @return list<Refusal>
     */
    public function validate(ToolCandidate $candidate): array;

    /** Run one call. The actor on the call is already resolved and authorized. */
    public function execute(ToolCall $call): SourceResult;
}
