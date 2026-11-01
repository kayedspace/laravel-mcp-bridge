<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

/** A prompt an application wrote, which the package only has to pass along. */
class CoursePrompt extends Prompt
{
    protected string $description = 'Draft a course outline.';

    public function handle(Request $request): Response
    {
        return Response::text('Draft an outline for a course.');
    }
}
