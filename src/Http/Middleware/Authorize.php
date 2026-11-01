<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who may open the token panel: a `viewMcpBridge` gate when the
 * application defines one, and the local environment alone when it does not. Production therefore fails
 * closed until someone writes the gate, which is the one moment they think
 * about who should hold agent tokens.
 *
 * A signed-in user is required even when the gate would allow a guest,
 * because a token belongs to someone and the panel issues it for them.
 */
final class Authorize
{
    public const GATE = 'viewMcpBridge';

    public function __construct(
        protected Application $app,
        protected Gate $gate,
        protected Repository $config,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->boolean('mcp-bridge.panel.enabled', true)) {
            throw new NotFoundHttpException;
        }

        $user = $request->user();

        $allowed = $user !== null && ($this->gate->has(self::GATE)
            ? $this->gate->forUser($user)->allows(self::GATE)
            : $this->app->environment('local'));

        if (! $allowed) {
            throw new AuthorizationException('The MCP token panel is open to the local environment and to users the [viewMcpBridge] gate allows.');
        }

        return $next($request);
    }
}
