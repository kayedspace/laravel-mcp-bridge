<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Resources\Concerns;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Security\TokenScopes;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * The authority rule, reaching the one surface it did not.
 *
 * Every tool is checked when listing and again when calling. App resources
 * were checked at neither: they are opt-in per item through
 * `sources.resource.expose`, which is an operator's choice, and then served
 * to any authenticated caller whatever its token holds.
 *
 * That is a narrower hole than it sounds, because the four resources return
 * an application's shape rather than its records, and `ConfigResource`
 * answers from an allowlist. It is still the wrong shape: a token scoped to
 * read one resource was being handed every route and every model's columns,
 * which is reconnaissance the operator never granted it.
 *
 * So resources take scopes too, from `sources.resource.scopes`, and are
 * checked in both places for the same reason tools are: hiding one is not
 * enforcement. `*` is any authenticated caller, which is the shipped value,
 * and an empty list is nobody: the list says what it grants, never more.
 */
trait RequiresResourceScopes
{
    public function shouldRegister(Request $request, Repository $config, TokenScopes $scopes): bool
    {
        return $this->refusalFor($request, $config, $scopes) === null;
    }

    /**
     * Why this caller may not read the resource, or null when it may. Asked
     * when listing and again on the way in, because a client that listed a
     * minute ago, or guessed the uri, reaches here without listing at all.
     */
    protected function refusalFor(Request $request, Repository $config, TokenScopes $scopes): ?Response
    {
        $required = array_values(array_filter(
            $config->array('mcp-bridge.sources.resource.scopes', ['*']),
            'is_string',
        ));

        if ($required === []) {
            return Response::error('No token may read this resource: sources.resource.scopes grants nothing.');
        }

        if (in_array('*', $required, true)) {
            return $request->user() === null
                ? Response::error('This resource needs an authenticated caller.')
                : null;
        }

        return $scopes->holds($request->user(), $required, 'resource')
            ? null
            : Response::error('This resource needs a scope this token does not hold.');
    }
}
