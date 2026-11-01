<?php

declare(strict_types=1);

namespace Workbench\App\Models;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
