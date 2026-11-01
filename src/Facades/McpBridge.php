<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Facades;

use Illuminate\Support\Facades\Facade;
use KayedSpace\McpBridge\McpBridgeManager;

/**
 * @method static \KayedSpace\McpBridge\Manifest\ManifestRepository manifest()
 * @method static bool isMcpCall(?\Illuminate\Http\Request $request = null)
 * @method static string|null currentClient(?\Illuminate\Http\Request $request = null)
 * @method static string|null currentTool(?\Illuminate\Http\Request $request = null)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null actor()
 * @method static mixed actingAs(\Illuminate\Contracts\Auth\Authenticatable $actor, callable $callback)
 * @method static string version()
 *
 * @see McpBridgeManager
 */
class McpBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return McpBridgeManager::class;
    }
}
