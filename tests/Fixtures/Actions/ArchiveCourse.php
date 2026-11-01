<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures\Actions;

use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/** No rules(): the signature is the declaration. */
#[McpTool]
#[McpScope('courses:write')]
#[McpAbility('publish-courses')]
#[IsDestructive]
class ArchiveCourse
{
    /** @return array<string, mixed> */
    public function __invoke(int $courseId, ?string $reason = null): array
    {
        return ['id' => $courseId, 'reason' => $reason];
    }
}
