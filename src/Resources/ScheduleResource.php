<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Resources;

use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use KayedSpace\McpBridge\Resources\Concerns\RequiresResourceScopes;
use KayedSpace\McpBridge\Security\TokenScopes;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/**
 * What this application does on a timer.
 *
 * Opt-in, because a schedule is a map of an application's background
 * behaviour: useful to an agent asked why a number changed overnight, and not
 * something to hand out by default.
 */
class ScheduleResource extends Resource
{
    use RequiresResourceScopes;

    protected string $name = 'schedule';

    protected string $title = 'Scheduled tasks';

    protected string $description = 'The commands and jobs this application runs on a schedule.';

    protected string $uri = 'app://schedule';

    protected string $mimeType = 'application/json';

    public function handle(Request $request, Schedule $schedule, Repository $mcpConfig, TokenScopes $mcpScopes): Response
    {
        $refused = $this->refusalFor($request, $mcpConfig, $mcpScopes);

        if ($refused !== null) {
            return $refused;
        }

        $events = array_map(
            fn (Event $event): array => array_filter([
                'description' => $event->description,
                'expression' => $event->expression,
                'timezone' => is_string($event->timezone) ? $event->timezone : null,
                'without_overlapping' => $event->withoutOverlapping,
                'on_one_server' => $event->onOneServer,
            ], fn (mixed $value): bool => $value !== null && $value !== false),
            $schedule->events(),
        );

        return Response::json(['events' => array_values($events), 'count' => count($events)]);
    }
}
