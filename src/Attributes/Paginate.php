<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Attributes;

use Attribute;

/**
 * How a list endpoint paginates, which decides whether the agent gets `page`
 * and `per_page` or a `cursor`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Paginate
{
    /**
     * @param  string  $type  length-aware, simple or cursor
     * @param  bool  $fixed  the page size is written into the call and cannot be changed, which is
     *                       what `->paginate(15)` means; the agent then gets `page` but no `per_page`,
     *                       because an input the application ignores is worse than no input
     */
    public function __construct(
        public int $perPage = 15,
        public ?int $max = null,
        public string $type = 'length-aware',
        public bool $fixed = false,
    ) {}

    public function isCursor(): bool
    {
        return $this->type === 'cursor';
    }
}
