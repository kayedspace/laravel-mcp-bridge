<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use KayedSpace\McpBridge\Filament\Pages\McpTokens;

/**
 * The package as a Filament plugin: a page where a signed-in user issues and
 * revokes their own agent tokens, so a token no longer needs an operator at
 * a shell. One page, and the plugin says so: the dashboard the design once
 * sketched is not built, and a plugin should register what exists.
 *
 *     $panel->plugin(McpBridgePlugin::make());
 */
final class McpBridgePlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'mcp-bridge';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([McpTokens::class]);
    }

    public function boot(Panel $panel): void {}
}
