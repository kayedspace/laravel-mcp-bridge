<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use KayedSpace\McpBridge\Http\Concerns\ResolvesPanelUser;
use KayedSpace\McpBridge\Models\McpToken;

/**
 * The calls an agent made as the signed-in user, from the audit trail: what
 * was asked, whether it was allowed, how long it took. The same rows an
 * operator reads from the table, scoped to one person and filterable by the
 * token that made them.
 */
final class PanelActivityController
{
    use ResolvesPanelUser;

    public function __construct(protected Factory $views) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $tokens = $this->tokensOf($user)->latest()->get();
        $filters = [
            'token' => (string) $request->query('token', ''),
            'outcome' => (string) $request->query('outcome', ''),
            'tool' => trim((string) $request->query('tool', '')),
        ];

        $calls = $this->callsOf($user);

        // A token filter is only honoured for a token of this user, so the
        // query string cannot widen the page to somebody else's calls.
        $owned = $tokens->contains(function ($token) use ($filters): bool {
            $key = $token->getKey();

            return is_scalar($key) && (string) $key === $filters['token'];
        });

        if ($filters['token'] !== '' && $owned) {
            $calls->where('token_type', (new McpToken)->getMorphClass())->where('token_id', $filters['token']);
        }

        if (in_array($filters['outcome'], ['allowed', 'refused', 'failed'], true)) {
            $calls->where('outcome', $filters['outcome']);
        }

        if ($filters['tool'] !== '') {
            $calls->where('tool', $filters['tool']);
        }

        /** @var view-string $view */
        $view = 'mcp-bridge::panel.activity';

        return $this->views->make($view, [
            'tokens' => $tokens,
            'filters' => $filters,
            'calls' => $calls->latest('id')->paginate(25)->withQueryString(),
        ]);
    }
}
