@extends('mcp-bridge::panel.layout')

@section('title', 'MCP activity')

@section('content')
    <h1>Activity</h1>
    <p>Every call an agent made as you, allowed or not. Arguments are recorded with secrets redacted.</p>

    <section class="card">
        <form method="get" class="filters">
            <div>
                <label for="token">Token</label>
                <select id="token" name="token">
                    <option value="">Any token</option>
                    @foreach ($tokens as $token)
                        <option value="{{ $token->getKey() }}" @selected((string) $filters['token'] === (string) $token->getKey())>{{ $token->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="outcome">Outcome</label>
                <select id="outcome" name="outcome">
                    <option value="">Any outcome</option>
                    @foreach (['allowed', 'refused', 'failed'] as $outcome)
                        <option value="{{ $outcome }}" @selected($filters['outcome'] === $outcome)>{{ ucfirst($outcome) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tool">Tool</label>
                <input type="text" id="tool" name="tool" value="{{ $filters['tool'] }}" placeholder="exact tool name">
            </div>
            <button class="button quiet" type="submit">Filter</button>
        </form>
    </section>

    <section class="card">
        @if ($calls->isEmpty())
            <p class="empty">No calls match. An agent that has not connected yet has nothing here.</p>
        @else
            <ul class="calls">
                @foreach ($calls as $call)
                    <li class="call">
                        <div class="call-head">
                            <code class="call-tool">{{ $call->tool }}</code>
                            <span class="badge {{ $call->outcome }}">{{ $call->outcome }}</span>
                            <time class="call-when" datetime="{{ $call->created_at?->toIso8601String() }}" title="{{ $call->created_at?->toDateTimeString() }}">{{ $call->created_at?->diffForHumans() }}</time>
                        </div>
                        <div class="call-meta">
                            @if ($call->error_code) <span class="mono">{{ $call->error_code }}</span> @endif
                            <span>{{ $call->credential === 'package token' ? ($tokens->firstWhere('id', $call->token_id)?->name ?? 'package token') : $call->credential }}</span>
                            @if ($call->client) <span>{{ $call->client }}</span> @endif
                            <span>{{ $call->duration_ms }} ms</span>
                            @if ($call->arguments === [])
                                <span>no arguments</span>
                            @endif
                        </div>
                        @if ($call->arguments !== [])
                            <details class="call-args">
                                <summary>{{ count($call->arguments) }} {{ count($call->arguments) === 1 ? 'argument' : 'arguments' }}</summary>
                                <pre>{{ json_encode($call->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="pager">
                <span>@if ($calls->previousPageUrl()) <a href="{{ $calls->previousPageUrl() }}">Newer</a> @endif</span>
                <span>Page {{ $calls->currentPage() }} of {{ $calls->lastPage() }}, {{ $calls->total() }} in all</span>
                <span>@if ($calls->nextPageUrl()) <a href="{{ $calls->nextPageUrl() }}">Older</a> @endif</span>
            </div>
        @endif
    </section>
@endsection
