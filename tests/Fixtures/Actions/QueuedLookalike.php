<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Actions;

use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpParam;
use KayedSpace\McpBridge\Attributes\McpQueue;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

/**
 * Declarations an action cannot honour: a queue, which only a job reads, and
 * a param naming a field the signature does not have. The lint has to say so.
 */
#[McpTool(description: 'Count the courses in a category.')]
#[McpScope('courses:write')]
#[McpAbility('publish-courses')]
#[McpQueue(queue: 'reports')]
#[McpParam('nothing_here', description: 'A field that does not exist.')]
class QueuedLookalike
{
    /** @return array<string, int> */
    public function __invoke(string $category): array
    {
        return ['count' => strlen($category)];
    }
}
