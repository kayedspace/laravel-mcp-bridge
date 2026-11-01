<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Resources;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use KayedSpace\McpBridge\Resources\Concerns\RequiresResourceScopes;
use KayedSpace\McpBridge\Security\TokenScopes;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/**
 * A summary of how this application is configured, for an agent that has to
 * reason about dates, money or language.
 *
 * A curated list, never the configuration itself. `config()` holds credentials,
 * keys and connection strings, and there is no safe way to filter a dump of it:
 * the only safe version is one that names what it will say in advance.
 */
class ConfigResource extends Resource
{
    use RequiresResourceScopes;

    protected string $name = 'config';

    protected string $title = 'Application settings';

    protected string $description = 'Name, environment, locale, timezone and framework versions.';

    protected string $uri = 'app://config';

    protected string $mimeType = 'application/json';

    public function handle(Request $request, Application $app, Repository $config, Repository $mcpConfig, TokenScopes $mcpScopes): Response
    {
        $refused = $this->refusalFor($request, $mcpConfig, $mcpScopes);

        if ($refused !== null) {
            return $refused;
        }

        return Response::json([
            'name' => $config->string('app.name', 'Laravel'),
            'environment' => $app->environment(),
            'locale' => $config->string('app.locale', 'en'),
            'fallback_locale' => $config->string('app.fallback_locale', 'en'),
            'timezone' => $config->string('app.timezone', 'UTC'),
            'currency' => $config->get('cashier.currency'),
            'laravel' => $app->version(),
            'php' => PHP_VERSION,
        ]);
    }
}
