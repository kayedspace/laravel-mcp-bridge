<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * Describe, retype or hide one input field, by its dot path.
 *
 * This is the most specific layer of the schema merge, so it wins over rules,
 * route parameters and documentation attributes alike. On a route it can
 * also add a field the rules never mentioned; on every other source it
 * shapes a field the source produced.
 *
 * `fromAuth` is the per-tool form of `security.fill_from_auth`: `true` hides
 * the field and fills it from the acting user, `false` keeps a globally
 * listed name in the agent's hands for this one tool, which is what an
 * admin tool that assigns records to other users needs. Read by
 * `ManifestCompiler` for every source.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class McpParam
{
    /** @param list<string> $enum */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public mixed $example = null,
        public array $enum = [],
        public ?string $type = null,
        public bool $hidden = false,
        public ?bool $fromAuth = null,
    ) {}
}
