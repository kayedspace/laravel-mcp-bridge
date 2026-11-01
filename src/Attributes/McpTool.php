<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Opt something in as a tool, and override how the agent sees it.
 *
 * On a controller class it applies to every action; on a method it overrides
 * the class. On a command, job or action class it is what opts that item in,
 * and the class is the only level there is.
 *
 * On a model, `operations` narrows `sources.model.operations` for that model
 * alone: a model that should only ever be read lists `['list', 'get']`. It
 * can only narrow, never widen, because widening a write from code would
 * lift an exposure default the configuration set, and that is the one
 * change in this package that cannot be quiet. Read by `ModelSource`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class McpTool
{
    /** @param list<string> $operations */
    public function __construct(
        public ?string $name = null,
        public ?string $title = null,
        public ?string $description = null,
        public array $operations = [],
    ) {}
}
