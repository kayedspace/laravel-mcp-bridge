@extends('mcp-bridge::panel.layout')

@section('title', 'MCP tokens')

@section('content')
    <h1>Tokens</h1>
    <p>Tokens an agent connects with as you. Give each one only the scopes it needs, and revoke it when the agent is done.</p>

    <div class="tiles">
        <div class="tile"><strong>{{ $summary['active'] }}</strong><span>active tokens</span></div>
        <div class="tile"><strong>{{ $summary['calls'] }}</strong><span>calls in the last 7 days</span></div>
        <div class="tile"><strong>{{ $summary['refused'] }}</strong><span>refused in the last 7 days</span></div>
        <div class="tile"><strong>{{ $summary['lastUsed']?->diffForHumans() ?? 'never' }}</strong><span>last call</span></div>
    </div>

    @if (session('mcp-bridge.plain'))
        <section class="card plain">
            <h2>Token issued. It is shown once.</h2>
            <p>Copy it now: only its hash is stored, and this page will not show it again.</p>
            <code>{{ session('mcp-bridge.plain') }}</code>
        </section>
    @endif

    @if (session('mcp-bridge.status'))
        <section class="card notice"><p>{{ session('mcp-bridge.status') }}</p></section>
    @endif

    <div class="toolbar">
        <h2 style="margin:0">Your tokens</h2>
        @if ($holdsTokens)
            <button class="button" type="button" data-open="issue-token">Issue a token</button>
        @endif
    </div>

    @unless ($holdsTokens)
        <section class="card notice">
            <h2>This account cannot hold a token yet</h2>
            <p>The user model needs the <code>HasMcpTokens</code> trait before a token issued here can be attached to it. Ask whoever maintains the application to add it.</p>
        </section>
    @endunless

    <section class="card">
        @forelse ($tokens as $token)
            <div class="token {{ $token->isUsable() ? '' : 'revoked' }}">
                <div>
                    <strong>{{ $token->name }}</strong>
                    <div class="meta">
                        Scopes <b>{{ $token->scopes === [] ? 'none' : implode(', ', $token->scopes) }}</b>
                        @if ($token->client) for <b>{{ $token->client }}</b> @endif
                        @if ($token->revoked_at)
                            , revoked {{ $token->revoked_at->diffForHumans() }}
                        @elseif ($token->expires_at)
                            , expires {{ $token->expires_at->toDayDateTimeString() }}
                        @else
                            , never expires
                        @endif
                    </div>
                    <div class="usage">
                        <span><b>{{ $usage[$token->getKey()]['calls'] ?? 0 }}</b> calls in 7 days</span>
                        <span><b>{{ $usage[$token->getKey()]['refused'] ?? 0 }}</b> refused</span>
                        <span>last used <b>{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</b></span>
                        <a class="meta" href="{{ route('mcp-bridge.panel.activity', ['token' => $token->getKey()]) }}">activity</a>
                    </div>
                </div>
                @if ($token->isUsable())
                    <form method="post" action="{{ route('mcp-bridge.panel.tokens.destroy', $token) }}">
                        @csrf
                        @method('DELETE')
                        <button class="button danger" type="submit">Revoke</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="empty">No tokens yet. Issue one to connect an agent.</p>
        @endforelse
        @if ($tokens->hasPages())
            <div class="pager">
                <span>@if ($tokens->previousPageUrl()) <a href="{{ $tokens->previousPageUrl() }}">Newer</a> @endif</span>
                <span>Page {{ $tokens->currentPage() }} of {{ $tokens->lastPage() }}, {{ $tokens->total() }} in all</span>
                <span>@if ($tokens->nextPageUrl()) <a href="{{ $tokens->nextPageUrl() }}">Older</a> @endif</span>
            </div>
        @endif
    </section>

    @if ($holdsTokens)
        <dialog id="issue-token" @if ($errors->any() || request()->boolean('issue')) data-reopen @endif>
            <form method="post" action="{{ route('mcp-bridge.panel.tokens.store') }}">
                @csrf
                <h2>Issue a token</h2>
                <p>The token is shown once after it is issued.</p>

                <label for="name">Name <small>What this token is for, so it can be told apart later.</small></label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="Claude" required>
                @error('name') <div class="error">{{ $message }}</div> @enderror

                <label>Scopes <small>Tick what the agent needs. Nothing ticked is a token that holds nothing.</small></label>
                <div class="scopes">
                    <label><input type="checkbox" name="scopes[]" value="*" @checked(in_array('*', old('scopes', []), true))> every scope (*)</label>
                    @foreach ($known as $scope)
                        <label><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', []), true))> {{ $scope }}</label>
                    @endforeach
                </div>
                @error('scopes') <div class="error">{{ $message }}</div> @enderror

                <label for="other_scopes">Other scopes <small>Comma separated, for a scope no tool on this server declares yet.</small></label>
                <input type="text" id="other_scopes" name="other_scopes" value="{{ old('other_scopes') }}" placeholder="reports:read, billing:read">
                @error('other_scopes') <div class="error">{{ $message }}</div> @enderror

                <label for="client">Client <small>Which agent this is for, recorded in the audit trail.</small></label>
                <input type="text" id="client" name="client" value="{{ old('client') }}" placeholder="claude-code">
                @error('client') <div class="error">{{ $message }}</div> @enderror

                <label for="expires_in_days">Expires in days <small>Leave empty for a token that never expires, which is a decision rather than a default.</small></label>
                <input type="number" id="expires_in_days" name="expires_in_days" value="{{ old('expires_in_days', $defaultDays) }}" min="1" max="3650">
                @error('expires_in_days') <div class="error">{{ $message }}</div> @enderror

                <div class="actions">
                    <button class="button quiet" type="button" data-close>Cancel</button>
                    <button class="button" type="submit">Issue token</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection
