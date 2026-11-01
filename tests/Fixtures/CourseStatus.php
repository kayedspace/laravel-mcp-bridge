<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
