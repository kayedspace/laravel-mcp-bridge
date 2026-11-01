<?php

declare(strict_types=1);
use KayedSpace\McpBridge\Http\Middleware\Authorize;

return [

    'enabled' => env('MCP_BRIDGE_ENABLED', true),

    'mode' => env('MCP_BRIDGE_MODE', 'opt-in'), // opt-in | opt-out | read-only

    // Past this many tools on one server, clients search the catalog instead
    // of listing it. 0 turns it off.
    'tool_search_threshold' => 40,

    // Also serve every server over stdio (`php artisan mcp:start <server>`).
    // Needs `security.local_actor`: a subprocess carries no token.
    'local' => env('MCP_BRIDGE_LOCAL', false),

    'servers' => [
        'default' => [
            'path' => '/mcp',
            'name' => env('APP_NAME', 'Laravel').' API',
            'instructions' => null, // null generates them from what the server serves; false sends none
            'middleware' => ['auth:mcp-bridge', 'throttle:mcp'],
            'groups' => ['*'],   // #[McpGroup] names served here; `*` is whatever arrives by source
            'sources' => ['route', 'resource'],
            'prompts' => [],     // laravel/mcp Prompt classes; nothing discovers these
            'tools' => [],       // your own laravel/mcp Tool classes, served beside the generated ones
            'resources' => [],   // your own laravel/mcp Resource classes
        ],

        'ops' => [
            'path' => '/mcp/ops',
            'name' => env('APP_NAME', 'Laravel').' Operations',
            'instructions' => null, // null generates them from what the server serves; false sends none
            'middleware' => ['auth:mcp-bridge', 'throttle:mcp-ops'],
            'groups' => ['ops'],
            'sources' => ['command', 'job', 'action', 'model', 'remote', 'openapi'], // a source on no server lands on default
        ],
    ],

    // In every list below, `*` is everything and [] is nothing. `exclude`,
    // `deny` and `except` take away from what the allowlist granted.
    'discovery' => [
        'prefixes' => ['api'],
        'include' => ['*'],
        'exclude' => ['api/*/webhooks/*', 'api/internal/*'],
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'domains' => ['*'],

        // Middleware a route must carry to be discovered: `auth:sanctum` is
        // exact, `auth:*` is any guard. Every pattern must match.
        'middleware' => [],
        'strip_version' => true,
        'cache' => env('MCP_BRIDGE_CACHE', true),
        'respect_docs_exclusions' => true,
    ],

    'sources' => [
        'route' => ['enabled' => true],

        'command' => [
            'enabled' => false,
            'server' => 'ops',
            'namespaces' => ['*'],  // command namespaces that may opt in
            'deny' => [],           // added to CommandFilter::ALWAYS_DENY, which cannot be lifted
            'allow_denied' => [],   // the only escape hatch, one command name at a time
            'destructive_by_default' => true,
            'timeout' => 60,
            'rate_limit' => '5/minute',
            'capture_output' => true,
            'output_tail_lines' => 20,
            'stream_output' => true, // each printed line is a progress notification when the client asked for progress
        ],

        'job' => [
            'enabled' => false,
            'server' => 'ops',
            'queue' => 'mcp-bridge',  // agent work never shares the application's queues
            'paths' => ['app/Jobs'],
            'timeout' => 30,
            'reauthorize' => true,    // re-check the Gate when the job runs, so a revoked token stops queued work
            'status_ttl' => '24h',    // how long `job_status` can answer after dispatch; lives in the cache
        ],
        'action' => [
            'enabled' => false,
            'paths' => ['app/Actions'],
        ],
        'model' => [
            'enabled' => false,
            'operations' => ['list', 'get'],  // create, update and delete are opt-in on top of a policy
            'require_policy' => true,         // no policy, no tools; `security.require_policy` is about route writes
            'paths' => ['app/Models'],
        ],
        'resource' => [
            'enabled' => true,
            'expose' => ['routes', 'schema'],
            'scopes' => ['*'],  // scopes a token needs to read these; `*` is any authenticated agent
        ],
        'remote' => [
            'enabled' => false,
            'servers' => [
                // 'billing' => [
                //     'url' => env('BILLING_MCP_URL'),
                //     'token' => env('BILLING_MCP_TOKEN'),
                //     'scopes' => ['billing:read'],   // what a caller of ours needs
                //     'abilities' => ['use-billing'],
                //     'only' => ['*'],                // which of its tools to re-export; [] is none
                //     'except' => [],
                //     'prefix' => 'billing',          // keeps two remotes from colliding
                // ],
            ],
        ],

        // Operations of an OpenAPI 3 document this application calls. The
        // authority is this application's, as for a remote.
        'openapi' => [
            'enabled' => false,
            'documents' => [
                // 'billing' => [
                //     'spec' => storage_path('openapi/billing.json'), // a file or a URL, JSON or YAML
                //     'base_url' => env('BILLING_API_URL'),           // overrides the document's first server
                //     'token' => env('BILLING_API_TOKEN'),            // sent as a bearer token
                //     'headers' => [],
                //     'timeout' => 10,
                //     'scopes' => ['billing:read'],                   // what a caller of ours needs
                //     'abilities' => ['use-billing'],
                //     'only' => ['*'],                                // operationIds or "GET /path"; [] is none
                //     'except' => [],
                //     'prefix' => 'billing',                          // keeps two documents from colliding
                // ],
            ],
        ],
    ],

    // Locators only decide which module a class belongs to, for grouping,
    // prefixes and per-module servers. Discovery needs none of this.
    'modules' => [
        'enabled' => true,
        'locators' => ['nwidart', 'path-repositories', 'directories'],
        'directories' => [],  // for the `directories` locator: globs whose matching directories are modules
        'only' => ['*'],
        'except' => [],
        'grouping' => 'module', // module | none
        'per_module_servers' => false,
        'always_prefix_module' => false,
    ],

    'execution' => [
        'forward_headers' => ['Accept-Language', 'User-Agent'], // outer request headers copied onto a route tool's sub-request

        // Classes with handle(ToolCall $call, Closure $next): ToolResult, run
        // after the gate and before the source. Return a ToolResult to stop.
        'middleware' => [],

        'idempotency' => [
            'enabled' => true,  // offers write tools an `idempotency_key`; a retried call gets the first answer
            'ttl' => '24h',
        ],
    ],

    'schema' => [
        'rule_mappers' => [],  // KayedSpace\McpBridge\Contracts\MapsRules classes, asked before the built-in mapping
    ],

    'naming' => [
        'pattern' => '{verb}_{resource}',
        'style' => 'snake',  // snake | camel | kebab
        'verbs' => [
            'index' => 'list',
            'show' => 'get',
            'store' => 'create',
            'update' => 'update',
            'destroy' => 'delete',
        ],

        // Prefix per source. Model tools mirror resource routes, so
        // `get_course` from both would collide. #[McpTool(name:)] is never prefixed.
        'source_prefixes' => ['model' => 'model'],
    ],

    'query' => [
        'enabled' => true,
        'read_chains' => true,     // read fluent Spatie QueryBuilder chains by static analysis
        'max_includes' => 3,       // per call
        'max_include_depth' => 2,  // lessons.author is two, lessons.author.company is three
    ],

    'protocol' => [
        'cache_hints' => [
            'enabled' => true,
            'ttl' => '5m',        // how long a client may reuse a list it already has
            'scope' => 'private', // tools/list is filtered per caller, so never shared
        ],
    ],

    'output' => [
        'max_items' => 25,
        'max_string' => 2000,
        'token_budget' => 8000,  // rows are dropped until the list fits; one row always survives; 0 turns it off
        'drop_nulls' => true,
        'structured' => true,    // send structuredContent as well as the text, which carries the same data
    ],

    'security' => [
        // passthrough: the call is the token holder's own. service-account:
        // every call runs as one configured user. For OAuth, point a server's
        // middleware at laravel/mcp's OAuth guard.
        'auth' => 'passthrough', // passthrough | service-account
        'service_account' => null, // an id or email of a real user, with real policies
        'local_actor' => env('MCP_BRIDGE_LOCAL_ACTOR'), // who a stdio call runs as under passthrough; read only when nothing authenticated the caller

        'destructive' => 'confirm', // allow | confirm | deny
        'expose_error_details' => false,
        'fill_from_auth' => ['user_id', 'created_by'],
        'require_policy' => false,  // refuse a route write whose bound model has no policy
        'guards' => ['mcp-bridge', 'sanctum'],
        'require_scope_for_non_http' => true,
        'confirm_handle_ttl' => '5m',
        'redact' => ['password', 'token', 'secret', 'national_id'],
    ],

    'integrations' => [
        'scramble' => [
            'enabled' => true,
            'read_headers' => false,
            'responses' => false,  // type route outputs from the response Scramble infers; seconds on first compile
        ],
        'scribe' => ['enabled' => true],
    ],

    'tokens' => ['prefix' => 'mcpb_', 'default_expiry' => '90d'],

    // Open in the local environment, elsewhere to users the `viewMcpBridge`
    // gate allows. Off answers 404.
    'panel' => [
        'enabled' => env('MCP_BRIDGE_PANEL', true),
        'path' => env('MCP_BRIDGE_PANEL_PATH', 'mcp-bridge'),
        'middleware' => ['web', Authorize::class],
    ],

    'uploads' => [
        'enabled' => true,
        'disk' => 'local',
        'max_kb' => 10240,
        'ttl' => '24h',   // how long a staged file waits to be used
    ],

    'audit' => [
        'driver' => 'database',  // database | log | none
        'retention_days' => 90,
    ],

    'database' => ['connection' => null, 'table_prefix' => 'mcp_bridge_'],

    // Per-route overrides, keyed by route name.
    'routes' => [
        // 'api.v1.courses.store' => ['name' => 'create_course', 'rate_limit' => '10/minute'],
    ],

];
