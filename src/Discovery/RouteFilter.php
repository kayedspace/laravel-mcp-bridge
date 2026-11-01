<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Discovery;

use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * The structural filter: which routes are even candidates, before anything
 * about exposure modes or attributes is considered.
 *
 * Every allowlist here reads the same way: `*` means everything, and an
 * empty list means nothing. An empty list that quietly meant everything was
 * a setting that exposed the most when it said the least.
 */
class RouteFilter
{
    /** Never callable by an agent, and never useful as a tool. */
    public const IGNORED_METHODS = ['HEAD', 'OPTIONS'];

    public const ANY = '*';

    public function __construct(protected Repository $config) {}

    public function passes(Route $route, string $httpMethod): bool
    {
        if (in_array($httpMethod, self::IGNORED_METHODS, true)) {
            return false;
        }

        if (! $this->listed($httpMethod, $this->strings('discovery.methods'))) {
            return false;
        }

        // A closure route carries no attributes and no FormRequest, so there is
        // nothing to infer a schema or an opt-in from.
        if ($route->getActionName() === '' || $route->getActionName() === 'Closure') {
            return false;
        }

        return $this->matchesPrefix($route)
            && $this->matchesDomain($route)
            && $this->hasRequiredMiddleware($route)
            && $this->included($route)
            && ! $this->excluded($route);
    }

    /**
     * Routes on a named host only, for an application that serves more than
     * one and does not mean all of them.
     */
    protected function matchesDomain(Route $route): bool
    {
        $domains = $this->strings('discovery.domains', [self::ANY]);

        if (in_array(self::ANY, $domains, true)) {
            return true;
        }

        $domain = $route->getDomain();

        if ($domain === null) {
            return false;
        }

        foreach ($domains as $pattern) {
            if (Str::is($pattern, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Routes carrying middleware the application insists on, which is the
     * cheapest way to say "nothing unauthenticated becomes a tool".
     *
     * A requirement list rather than an allowlist: every pattern listed has
     * to match, so an empty list requires nothing, which is the one place
     * here an empty list is not a closed door.
     *
     * Matched against the middleware names as the router reports them, so
     * `auth:sanctum` matches exactly and `auth:*` matches any guard.
     */
    protected function hasRequiredMiddleware(Route $route): bool
    {
        $required = $this->strings('discovery.middleware');

        if ($required === []) {
            return true;
        }

        $applied = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

        foreach ($required as $pattern) {
            $found = false;

            foreach ($applied as $middleware) {
                if (Str::is($pattern, $middleware)) {
                    $found = true;

                    break;
                }
            }

            // Every required pattern has to match something: a route missing
            // one of them is a route the application did not mean to expose.
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * The HTTP methods of a route that are worth considering, with PUT and
     * PATCH collapsed into one update tool.
     *
     * @return list<string>
     */
    public function methodsFor(Route $route): array
    {
        $methods = array_values(array_diff($route->methods(), self::IGNORED_METHODS));

        if (in_array('PUT', $methods, true) && in_array('PATCH', $methods, true)) {
            $methods = array_values(array_diff($methods, ['PUT']));
        }

        return array_values(array_filter($methods, fn (string $method): bool => $this->passes($route, $method)));
    }

    protected function matchesPrefix(Route $route): bool
    {
        $prefixes = $this->strings('discovery.prefixes', ['api']);

        if (in_array(self::ANY, $prefixes, true)) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '/');

            if ($route->uri() === $prefix || Str::startsWith($route->uri(), $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    protected function included(Route $route): bool
    {
        return $this->matchesAny($route, $this->strings('discovery.include', [self::ANY]));
    }

    protected function excluded(Route $route): bool
    {
        return $this->matchesAny($route, $this->strings('discovery.exclude'));
    }

    /**
     * Patterns are matched against the URI, the route name and the controller.
     * `*` matches any of them, which is what makes it the wildcard.
     *
     * @param  list<string>  $patterns
     */
    protected function matchesAny(Route $route, array $patterns): bool
    {
        $subjects = array_filter([
            $route->uri(),
            $route->getName(),
            $route->getActionName(),
        ]);

        foreach ($patterns as $pattern) {
            foreach ($subjects as $subject) {
                if (Str::is($pattern, $subject)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $allowed */
    protected function listed(string $value, array $allowed): bool
    {
        return in_array(self::ANY, $allowed, true) || in_array($value, $allowed, true);
    }

    /**
     * A list from the configuration, with the shipped default for a key an
     * older published file does not have, so a missing key reads as the
     * package intends rather than as an empty list.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function strings(string $key, array $default = []): array
    {
        return array_values(array_filter($this->config->array("mcp-bridge.{$key}", $default), 'is_string'));
    }
}
