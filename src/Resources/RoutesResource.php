<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Resources;

use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use KayedSpace\McpBridge\Discovery\RouteFilter;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Resources\Concerns\RequiresResourceScopes;
use KayedSpace\McpBridge\Security\TokenScopes;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/**
 * The application's API surface, as a resource rather than a tool.
 *
 * An agent that can see every endpoint, including the ones that are not
 * tools, can tell the difference between "this application cannot do that"
 * and "that is not exposed to me", and ask for the right thing instead of
 * trying tool after tool.
 *
 * Methods, URIs, route names and tool names: what a REST client could learn
 * from the documentation. Never the controller behind each: an application's
 * class layout is reconnaissance an agent has no use for and an operator
 * never meant to hand out.
 */
class RoutesResource extends Resource
{
    use RequiresResourceScopes;

    protected string $name = 'routes';

    protected string $title = 'HTTP routes';

    protected string $description = 'Every API route this application serves, and which of them are tools.';

    protected string $uri = 'app://routes';

    protected string $mimeType = 'application/json';

    public function handle(Request $request, Router $router, RouteFilter $filter, ManifestRepository $manifest, Repository $mcpConfig, TokenScopes $mcpScopes): Response
    {
        $refused = $this->refusalFor($request, $mcpConfig, $mcpScopes);

        if ($refused !== null) {
            return $refused;
        }

        $tools = [];

        foreach ($manifest->all() as $definition) {
            $tools[$definition->candidateId] = $definition->name;
        }

        $routes = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($filter->methodsFor($route) as $method) {
                $routes[] = [
                    'method' => $method,
                    'uri' => '/'.ltrim($route->uri(), '/'),
                    'name' => $route->getName(),
                    'tool' => $tools[$this->id($route, $method)] ?? null,
                ];
            }
        }

        return Response::json(['routes' => $routes, 'count' => count($routes)]);
    }

    /** The candidate id RouteSource would have given this route. */
    protected function id(Route $route, string $method): string
    {
        $name = $route->getName();

        return $name === null || $name === ''
            ? "route:{$method} {$route->uri()}"
            : "route:{$name}:{$method}";
    }
}
