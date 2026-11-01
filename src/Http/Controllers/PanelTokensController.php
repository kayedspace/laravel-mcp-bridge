<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Http\Controllers;

use Illuminate\Config\Repository;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use KayedSpace\McpBridge\Http\Concerns\ResolvesPanelUser;
use KayedSpace\McpBridge\Http\Requests\IssueTokenRequest;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Models\McpToken;
use KayedSpace\McpBridge\Support\Duration;

/**
 * The token panel: a signed-in user's own tokens, issued and revoked by
 * them. The same page the Filament plugin adds, for an application without
 * Filament, which is why it is three routes and one view and nothing that
 * needs building.
 */
final class PanelTokensController
{
    use ResolvesPanelUser;

    public function __construct(
        protected Factory $views,
        protected Redirector $redirect,
        protected ManifestRepository $manifest,
        protected Repository $config,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $tokens = $this->tokensOf($user)->latest()->paginate(10)->withQueryString();
        $usage = $this->usage($user);
        $recent = $this->recent($user);

        /** @var view-string $view */
        $view = 'mcp-bridge::panel.tokens';

        return $this->views->make($view, [
            'tokens' => $tokens,
            'usage' => $usage,
            'summary' => [
                'active' => $this->tokensOf($user)->usable()->count(),
                'calls' => $recent['calls'],
                'refused' => $recent['refused'],
                'lastUsed' => $recent['last'] ?? $this->tokensOf($user)->max('last_used_at'),
            ],
            'known' => $this->knownScopes(),
            'holdsTokens' => $this->holdsTokens($user),
            'defaultDays' => max(1, (int) round(Duration::seconds($this->config->get('mcp-bridge.tokens.default_expiry'), 90 * 86400) / 86400)),
        ]);
    }

    public function store(IssueTokenRequest $request): RedirectResponse
    {
        // The same refusal the CLI gives: a token the guard cannot attach to
        // its owner is a token every scoped tool refuses.
        if (! $this->holdsTokens($this->user($request))) {
            throw ValidationException::withMessages([
                'name' => 'The user model needs the HasMcpTokens trait before it can hold a token.',
            ]);
        }

        $name = $request->validated('name');
        $days = $request->validated('expires_in_days');
        $client = $request->validated('client');

        $plain = McpToken::issue(
            tokenable: $this->user($request),
            name: is_string($name) ? $name : 'Agent token',
            scopes: $request->scopes(),
            expiresAt: is_numeric($days) ? Carbon::now()->addDays((int) $days) : null,
            client: is_string($client) && $client !== '' ? $client : null,
        )->plainText;

        // The only moment the plain token exists: flashed to the one page
        // that follows, and gone with it.
        return $this->redirect->route('mcp-bridge.panel.tokens.index')->with('mcp-bridge.plain', $plain);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        // Scoped to the owner before the lookup, so another user's token is
        // not found rather than forbidden, and its existence is not told.
        $this->tokensOf($this->user($request))
            ->whereKey($token)
            ->firstOrFail()
            ->revoke();

        return $this->redirect->route('mcp-bridge.panel.tokens.index')
            ->with('mcp-bridge.status', 'Token revoked. An agent connected with it stops at its next request.');
    }

    /**
     * Every scope a tool on the manifest asks for, so the form offers real
     * names to tick instead of a free-text field to misspell into.
     *
     * @return list<string>
     */
    protected function knownScopes(): array
    {
        $scopes = [];

        foreach ($this->manifest->all() as $definition) {
            foreach ($definition->authority->scopes as $scope) {
                $scopes[$scope] = true;
            }
        }

        ksort($scopes);

        return array_keys($scopes);
    }

    /**
     * Calls and refusals per token over the last seven days, from the audit
     * trail. Empty when the trail is not in the database, which is a
     * configuration this page respects rather than reports.
     *
     * @return array<int|string, array{calls: int, refused: int}>
     */
    protected function usage(Model $user): array
    {
        if ($this->config->string('mcp-bridge.audit.driver', 'database') !== 'database') {
            return [];
        }

        if (! $this->audited()) {
            return [];
        }

        $rows = $this->callsOf($user)
            ->where('token_type', (new McpToken)->getMorphClass())
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->selectRaw("token_id, count(*) as calls, sum(case when outcome = 'refused' then 1 else 0 end) as refused")
            ->groupBy('token_id')
            ->get();

        $usage = [];

        foreach ($rows as $row) {
            $token = $row->getAttribute('token_id');
            $calls = $row->getAttribute('calls');
            $refused = $row->getAttribute('refused');

            $usage[is_scalar($token) ? (string) $token : ''] = [
                'calls' => is_numeric($calls) ? (int) $calls : 0,
                'refused' => is_numeric($refused) ? (int) $refused : 0,
            ];
        }

        return $usage;
    }

    /**
     * Every call made as this user in the last seven days, whatever it came
     * with: a package token, a Sanctum token, another guard or a session.
     *
     * @return array{calls: int, refused: int, last: Carbon|null}
     */
    protected function recent(Model $user): array
    {
        if (! $this->audited()) {
            return ['calls' => 0, 'refused' => 0, 'last' => null];
        }

        $row = $this->callsOf($user)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->selectRaw("count(*) as calls, sum(case when outcome = 'refused' then 1 else 0 end) as refused, max(created_at) as last")
            ->toBase()
            ->first();

        $calls = $row->calls ?? 0;
        $refused = $row->refused ?? 0;
        $last = $row->last ?? null;

        return [
            'calls' => is_numeric($calls) ? (int) $calls : 0,
            'refused' => is_numeric($refused) ? (int) $refused : 0,
            'last' => is_string($last) ? Carbon::parse($last) : null,
        ];
    }

    /**
     * Asked rather than caught: a failed query inside a transaction makes
     * PostgreSQL refuse every query after it. No table means the migrations
     * have not run, and doctor says so.
     */
    protected function audited(): bool
    {
        $audit = new McpAuditEntry;

        return $audit->getConnection()->getSchemaBuilder()->hasTable($audit->getTable());
    }
}
