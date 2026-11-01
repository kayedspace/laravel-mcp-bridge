# OpenAPI documents

An OpenAPI source turns the operations of an OpenAPI 3 document into tools, for an API this application reaches rather than serves: a billing provider, a sibling service, a vendor. The point is the same as for a [remote MCP server](/sources/remote): one endpoint and one token for an agent that would otherwise hold several, with the API's own credential never leaving this server.

The document is already a declaration, so nothing is inferred. Its parameters and request body are the schema, its `operationId` is the name, and its summary is the description.

## What it exposes

Every operation (`get`, `post`, `put`, `patch`, `delete`) under `paths` in each configured document, optionally narrowed with `only` and `except`. A document is a file or a URL, in JSON or, when `symfony/yaml` is installed, YAML. References to the same document (`$ref: '#/components/...'`) are resolved and `allOf` is folded into one object, so a schema is read the way its author meant it.

A document that cannot be read when the manifest compiles yields no tools rather than failing the build: the rest of the surface is still correct. An operation whose request body is not `application/json` is refused with the media types the document offers, because an agent speaks JSON and a form or a file upload is not a call it can make.

## What it needs to be opted in

The source is off by default. Opting in is configuration: enable it and list a document under `sources.openapi.documents` with at least a `spec`.

Authority is **this application's**, not the API's. Every operation of a document declares the `scopes` and `abilities` in its config block, and a block with neither is refused like any other non-HTTP candidate, with "needs both #[McpScope] and #[McpAbility]".

## Example

```php
// config/mcp-bridge.php
'sources' => [
    'openapi' => [
        'enabled' => true,
        'documents' => [
            'billing' => [
                'spec' => storage_path('openapi/billing.json'),
                'base_url' => env('BILLING_API_URL'),
                'token' => env('BILLING_API_TOKEN'),
                'scopes' => ['billing:read'],
                'abilities' => ['use-billing'],
                'only' => ['*'],
                'except' => ['voidInvoice'],
                'prefix' => 'billing',
            ],
        ],
    ],
],
```

A document with `listInvoices`, `createInvoice`, `getInvoice` and `voidInvoice` compiles to `billing_list_invoices`, `billing_create_invoice` and `billing_get_invoice`, with `voidInvoice` left out. An operation without an `operationId` is named from its method and path, as a route without a name is.

| Document key | Meaning |
| --- | --- |
| `spec` | A file path or a URL. Required. |
| `base_url` | Where the paths live. Defaults to the document's first `servers` entry; one or the other is needed to call anything. |
| `token` | Sent as a bearer token. |
| `headers` | Extra headers, as a name to value map. |
| `timeout` | Request timeout in seconds. Defaults to `sources.openapi.timeout`, and to `30` when that is unset. |
| `scopes`, `abilities` | The authority an actor of this application needs. Required. |
| `only`, `except` | `operationId`s, or `METHOD /path` entries such as `DELETE /invoices/{id}`. `only` is an allowlist: `*`, or leaving it out, exposes every operation, and an empty list exposes none. `except` removes from that. |
| `prefix` | Prepended to every name, snake-cased. Defaults to the document key. An empty string means no prefix. |

## The schema comes from the document

| Part of the operation | Becomes |
| --- | --- |
| A `path` parameter | A required input, since the URL has a hole in it. Parameters declared on the path item apply to every operation under it. |
| A `query` parameter | An input, required when the document says so, with the parameter's type, enum, format, bounds and description. |
| The `application/json` request body | Inputs for its properties, nested objects flattened to dotted paths and arrays typed by their items, exactly as a remote tool's schema is read. |
| A `header` or `cookie` parameter | Nothing. Those are transport concerns, and the credential is configured, not sent by the agent. |

## How it runs

`OpenApiExecutor` builds the URL from `base_url` and the path with its parameters filled in, puts query inputs in the query string and body inputs in a JSON body, sends the configured token and headers plus `X-Request-Id` and `X-Mcp-Tool`, and asks for JSON back. The agent's own identity is never forwarded: the API has never heard of it.

The status comes back as the API answered it, so a `404` or a `422` is mapped like a route's. An API that cannot be reached answers `MCP_BRIDGE_REMOTE_UNAVAILABLE` (HTTP 502), and one that answers something other than JSON answers `MCP_BRIDGE_NON_JSON`. The gate still runs: scopes, abilities, the rate limit, the kill switch and the audit trail apply as to any tool.

## Safety is inferred from the verb

As for a route: `GET` is read-only and idempotent, `PUT` is idempotent, `DELETE` is destructive and idempotent, anything else is a plain write. `DELETE` therefore asks for [confirmation](/guide/security#destructive-calls-and-confirmation) by default. A `POST` that destroys data is not inferred as destructive, and a document carries no attribute to say so; keep such an operation out with `except`, or accept that confirmation is not asked.

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `sources.openapi.enabled` | `false` | Switch the source on. |
| `sources.openapi.documents` | `[]` | Documents, keyed by name. |

The shipped `ops` server lists `openapi` among its sources, so these tools land on `/mcp/ops`, behind `auth:mcp-bridge`, beside the remote tools. An operation's group is its document key, `billing` here. Set `sources.openapi.server` to place them elsewhere, or list a document's key in a server's `groups` to move just that document; the full order is in [where a tool lands](/guide/servers-and-modules#where-a-tool-lands).
