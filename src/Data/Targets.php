<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * The one place that turns a stored target back into its class.
 *
 * @phpstan-import-type RouteTargetArray from RouteTarget
 * @phpstan-import-type CommandTargetArray from CommandTarget
 * @phpstan-import-type ClassTargetArray from ClassTarget
 * @phpstan-import-type RemoteTargetArray from RemoteTarget
 * @phpstan-import-type OpenApiTargetArray from OpenApiTarget
 *
 * @phpstan-type TargetArray RouteTargetArray|CommandTargetArray|ClassTargetArray|RemoteTargetArray|OpenApiTargetArray
 */
final class Targets
{
    /** @param TargetArray $data */
    public static function fromArray(array $data): Target
    {
        return match ($data['type']) {
            'route' => RouteTarget::fromArray($data),
            'command' => CommandTarget::fromArray($data),
            'class' => ClassTarget::fromArray($data),
            'remote' => RemoteTarget::fromArray($data),
            'openapi' => OpenApiTarget::fromArray($data),
        };
    }
}
