<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'MCP Bridge')</title>
    <style>
        :root {
            --paper: #fafaf9; --card: #ffffff; --ink: #161615; --muted: #6b6a65; --line: #e7e6e3; --soft: #f2f2f0;
            --brand: #161615; --brand-ink: #ffffff; --agent: #868682; --mark: #ef3b2d; --signal: #92400e; --signal-bg: #fdf6e7;
            --ok: #166534; --ok-bg: #f0f7f2; --refused: #991b1b; --refused-bg: #fbf0f0; --failed: #7c2d12; --failed-bg: #fbf3ec;
            --shadow: 0 1px 2px rgba(22, 22, 21, .06), 0 0 0 1px var(--line);
        }
        [data-theme="dark"] {
            --paper: #0b0b0b; --card: #161615; --ink: #ededec; --muted: #a1a09a; --line: #262625; --soft: #1f1f1e;
            --brand: #ededec; --brand-ink: #0b0b0b; --agent: #a1a09a; --mark: #ef3b2d; --signal: #fbbf24; --signal-bg: #241d0c;
            --ok: #86efac; --ok-bg: #0f1f15; --refused: #fca5a5; --refused-bg: #2a1212; --failed: #fdba74; --failed-bg: #2a1a0f;
            --shadow: 0 1px 2px rgba(0, 0, 0, .5), 0 0 0 1px var(--line);
        }
        @media (prefers-color-scheme: dark) {
            [data-theme="auto"] {
                --paper: #0b0b0b; --card: #161615; --ink: #ededec; --muted: #a1a09a; --line: #262625; --soft: #1f1f1e;
                --brand: #ededec; --brand-ink: #0b0b0b; --agent: #a1a09a; --mark: #ef3b2d; --signal: #fbbf24; --signal-bg: #241d0c;
                --ok: #86efac; --ok-bg: #0f1f15; --refused: #fca5a5; --refused-bg: #2a1212; --failed: #fdba74; --failed-bg: #2a1a0f;
                --shadow: 0 1px 2px rgba(0, 0, 0, .5), 0 0 0 1px var(--line);
            }
        }
        * { box-sizing: border-box; }
        html { color-scheme: light dark; }
        body { margin: 0; background: var(--paper); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: inherit; }
        header { display: flex; align-items: center; gap: 1.5rem; max-width: 64rem; margin: 0 auto; padding: 1.25rem 1.25rem 0; }
        .brand { display: flex; align-items: center; gap: .6rem; font-weight: 700; text-decoration: none; }
        .brand svg { width: 26px; height: 26px; }
        nav { display: flex; gap: .25rem; margin-left: .5rem; }
        nav a { text-decoration: none; padding: .4rem .7rem; border-radius: 8px; color: var(--muted); font-weight: 500; }
        nav a[aria-current="page"] { background: var(--soft); color: var(--ink); }
        .spacer { flex: 1; }
        .theme { display: inline-flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
        .theme button { border: 0; background: transparent; color: var(--muted); padding: .35rem .6rem; cursor: pointer; font: inherit; font-size: .8rem; }
        .theme button[aria-pressed="true"] { background: var(--soft); color: var(--ink); }
        main { max-width: 64rem; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
        h2 { font-size: 1rem; margin: 0 0 1rem; }
        p { margin: 0; color: var(--muted); }
        .card { background: var(--card); border-radius: 12px; padding: 1.25rem 1.5rem; margin-top: 1.25rem; box-shadow: var(--shadow); }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 1rem; margin-top: 1.25rem; }
        .tile { background: var(--card); border-radius: 12px; padding: 1rem 1.25rem; box-shadow: var(--shadow); }
        .tile strong { display: block; font-size: 1.6rem; font-weight: 700; letter-spacing: -.02em; }
        .tile span { color: var(--muted); font-size: .875rem; }
        .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 1.75rem; }
        .button { display: inline-flex; align-items: center; gap: .4rem; padding: .55rem 1rem; border: 0; border-radius: 8px; background: var(--brand); color: var(--brand-ink); font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .button.quiet { background: transparent; color: var(--ink); box-shadow: 0 0 0 1px var(--line); }
        .button.danger { background: transparent; color: var(--refused); box-shadow: 0 0 0 1px var(--line); padding: .35rem .7rem; }
        code, .mono { font-family: ui-monospace, "JetBrains Mono", SFMono-Regular, Menlo, monospace; font-size: .85em; }
        .plain { background: var(--ok-bg); color: var(--ok); }
        .plain code { display: block; margin-top: .5rem; font-size: .95rem; user-select: all; word-break: break-all; }
        .notice { border-left: 4px solid var(--brand); }
        .token { display: grid; grid-template-columns: 1fr auto; gap: .75rem 1rem; align-items: start; padding: .9rem 0; }
        .token + .token { border-top: 1px solid var(--line); }
        .token strong { display: block; }
        .meta { color: var(--muted); font-size: .875rem; }
        .meta b { color: var(--ink); font-weight: 600; }
        .usage { display: flex; gap: 1.25rem; margin-top: .35rem; font-size: .875rem; color: var(--muted); }
        .usage b { color: var(--ink); font-weight: 600; }
        .revoked { opacity: .55; }
        .badge { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
        .badge.allowed { background: var(--ok-bg); color: var(--ok); }
        .badge.refused { background: var(--refused-bg); color: var(--refused); }
        .badge.failed { background: var(--failed-bg); color: var(--failed); }
        .calls { list-style: none; margin: 0; padding: 0; }
        .call { padding: .9rem 0; min-width: 0; }
        .call + .call { border-top: 1px solid var(--line); }
        .call-head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; }
        .call-tool { font-size: .9rem; font-weight: 600; overflow-wrap: anywhere; }
        .call-when { margin-left: auto; color: var(--muted); font-size: .85rem; white-space: nowrap; }
        .call-meta { display: flex; flex-wrap: wrap; gap: .25rem 1rem; margin-top: .3rem; color: var(--muted); font-size: .85rem; }
        .call-args { margin-top: .5rem; }
        .call-args pre { max-height: 22rem; white-space: pre-wrap; overflow-wrap: anywhere; }
        details summary { cursor: pointer; color: var(--muted); font-size: .8rem; }
        details pre { margin: .4rem 0 0; padding: .6rem .75rem; background: var(--soft); border-radius: 8px; font-size: .8rem; overflow: auto; }
        details summary::marker { color: var(--muted); }
        .filters { display: flex; gap: .75rem; flex-wrap: wrap; align-items: end; }
        .filters label { display: block; font-size: .8rem; color: var(--muted); }
        select, input[type=text], input[type=number] { width: 100%; margin-top: .3rem; padding: .5rem .65rem; border: 1px solid var(--line); border-radius: 8px; background: var(--card); color: inherit; font: inherit; }
        .filters select { width: auto; min-width: 11rem; }
        .pager { display: flex; justify-content: space-between; margin-top: 1rem; }
        .pager a, .pager span { color: var(--muted); text-decoration: none; }
        .pager a:hover { color: var(--ink); }
        dialog { border: 0; border-radius: 14px; padding: 0; background: var(--card); color: var(--ink); width: min(34rem, calc(100vw - 2rem)); box-shadow: 0 20px 60px rgba(0,0,0,.35), 0 0 0 1px var(--line); }
        dialog::backdrop { background: rgba(0, 0, 0, .6); backdrop-filter: blur(2px); }
        input[type=checkbox] { accent-color: var(--brand); }
        :focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
        dialog form { padding: 1.5rem 1.75rem; }
        dialog h2 { margin-bottom: .25rem; font-size: 1.15rem; }
        dialog label { display: block; font-weight: 600; margin-top: 1rem; }
        dialog label small { display: block; font-weight: 400; color: var(--muted); }
        .scopes { display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: .35rem .75rem; margin-top: .5rem; }
        .scopes label { margin: 0; font-weight: 400; display: flex; gap: .5rem; align-items: center; }
        .error { color: var(--refused); font-size: .875rem; margin-top: .25rem; }
        .actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1.5rem; }
        .empty { color: var(--muted); padding: 1rem 0; }

        /* Phones and narrow windows: one column, the header wraps, the dialog
           takes the screen, and nothing scrolls sideways. */
        @media (max-width: 640px) {
            body { font-size: 14px; }
            header { flex-wrap: wrap; gap: .75rem 1rem; padding: 1rem 1rem 0; }
            nav { margin-left: 0; order: 3; width: 100%; }
            nav a { flex: 1; text-align: center; }
            main { padding: 1.25rem 1rem 3rem; }
            h1 { font-size: 1.3rem; }
            .card { padding: 1rem 1.1rem; border-radius: 10px; }
            .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .6rem; }
            .tile { padding: .8rem .9rem; }
            .tile strong { font-size: 1.25rem; }
            .toolbar { flex-wrap: wrap; }
            .token { grid-template-columns: 1fr; }
            .token form .button { width: 100%; justify-content: center; }
            .usage { flex-wrap: wrap; gap: .25rem 1rem; }
            .filters { flex-direction: column; align-items: stretch; }
            .filters select { width: 100%; min-width: 0; }
            .filters .button { justify-content: center; }
            dialog { width: 100vw; max-width: 100vw; max-height: 100dvh; margin: auto 0 0; border-radius: 14px 14px 0 0; }
            dialog form { padding: 1.25rem 1.1rem; }
            .actions .button { flex: 1; justify-content: center; }
            .call-when { margin-left: 0; width: 100%; order: 3; }
        }
    </style>
</head>
<body>
<header>
    <a class="brand" href="{{ route('mcp-bridge.panel.tokens.index') }}">
        <svg viewBox="0 0 512 512" aria-hidden="true" fill="var(--mark)"><path d="M64 96 H168 V144 H116 V368 H168 V416 H64 Z"/><path d="M448 96 H344 V144 H396 V368 H344 V416 H448 Z"/><path d="M198 182 L250 152 L250 360 L198 330 Z"/><path d="M314 182 L262 152 L262 360 L314 330 Z"/></svg>
        MCP Bridge
    </a>
    <nav>
        <a href="{{ route('mcp-bridge.panel.tokens.index') }}" @if (request()->routeIs('mcp-bridge.panel.tokens.*')) aria-current="page" @endif>Tokens</a>
        <a href="{{ route('mcp-bridge.panel.activity') }}" @if (request()->routeIs('mcp-bridge.panel.activity')) aria-current="page" @endif>Activity</a>
    </nav>
    <div class="spacer"></div>
    <div class="theme" role="group" aria-label="Theme">
        <button type="button" data-set-theme="light">Light</button>
        <button type="button" data-set-theme="auto">System</button>
        <button type="button" data-set-theme="dark">Dark</button>
    </div>
</header>
<main>
    @yield('content')
</main>
<script>
    (function () {
        var root = document.documentElement;
        var buttons = document.querySelectorAll('[data-set-theme]');
        function apply(theme) {
            root.setAttribute('data-theme', theme);
            buttons.forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.setTheme === theme)); });
        }
        apply(localStorage.getItem('mcp-bridge-theme') || 'dark');
        buttons.forEach(function (b) {
            b.addEventListener('click', function () { localStorage.setItem('mcp-bridge-theme', b.dataset.setTheme); apply(b.dataset.setTheme); });
        });
        document.querySelectorAll('[data-open]').forEach(function (b) {
            b.addEventListener('click', function () { document.getElementById(b.dataset.open).showModal(); });
        });
        document.querySelectorAll('[data-close]').forEach(function (b) {
            b.addEventListener('click', function () { b.closest('dialog').close(); });
        });
        var reopen = document.querySelector('dialog[data-reopen]');
        if (reopen) { reopen.showModal(); }
    })();
</script>
</body>
</html>
