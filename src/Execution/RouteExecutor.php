<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use KayedSpace\McpBridge\Contracts\ExecutesTools;
use KayedSpace\McpBridge\Data\RouteTarget;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Uploads\UploadStore;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs a tool call as an in-process request through the HTTP kernel, so the
 * target route behaves exactly as it does for a REST client: the global
 * middleware, the route's own middleware, guard, FormRequest and policies,
 * and the exception handler all apply.
 *
 * Through the kernel rather than the router, which is what the first version
 * did. The router alone skipped everything the kernel adds: input was not
 * trimmed, maintenance mode did not hold, an application's own global
 * middleware never ran, and an exception a route threw was caught here and
 * never reported, so a 500 an agent caused left no trace in the
 * application's log. The kernel renders every failure as the JSON a REST
 * client would get, and that JSON is what is mapped.
 *
 * The kernel is not asked to terminate the sub-request. Its terminating
 * middleware includes the one that flushes deferred callbacks, and that
 * would flush, or on a failed call drop, what the outer request had
 * deferred before the tool ran. What a route defers still runs, at the end
 * of the request that carried the tool call.
 */
class RouteExecutor implements ExecutesTools
{
    /** Headers this executor sets itself, never overwritten from input or forwarded. */
    protected const RESERVED = ['authorization', 'content-type', 'accept', 'x-request-id', 'x-mcp-tool', 'x-mcp-client'];

    public function __construct(
        protected Application $app,
        protected Kernel $kernel,
        protected WireSerializer $wire,
        protected UploadStore $uploads,
        protected Repository $config,
    ) {}

    public function execute(ToolCall $call): SourceResult
    {
        $target = $call->definition->target;

        if (! $target instanceof RouteTarget) {
            return SourceResult::failed(500, ['message' => 'This tool is not backed by a route.']);
        }

        $snapshot = StateSnapshot::capture($this->app);

        try {
            $request = $this->request($call, $target);

            $this->handoff($call);

            return $this->result($this->kernel->handle($request));
        } catch (Throwable $exception) {
            // The kernel renders what a route throws, so anything arriving
            // here left the kernel itself.
            return SourceResult::failed(500, null, $exception);
        } finally {
            $snapshot->restore();
        }
    }

    protected function request(ToolCall $call, RouteTarget $target): Request
    {
        $query = $this->wire->query($call);
        $body = $this->wire->body($call);
        $outer = $this->outer();

        $uri = $this->wire->path($target->uri, $call);
        $queryString = $query === [] ? '' : '?'.http_build_query($query);
        $ip = $outer?->ip();

        $request = Request::create(
            uri: $this->base($outer).'/'.ltrim($uri, '/').$queryString,
            method: $target->method,
            parameters: [],
            cookies: [],
            files: [],
            // The caller's address, so an IP-keyed throttle or a log inside
            // the route sees the agent rather than the loopback.
            server: $ip === null ? [] : ['REMOTE_ADDR' => $ip],
            content: $body === [] ? null : (string) json_encode($body),
        );

        $request->headers->set('Accept', 'application/json');

        if ($body !== []) {
            $request->headers->set('Content-Type', 'application/json');
        }

        $request->headers->set('X-Request-Id', $call->requestId);
        $request->headers->set('X-Mcp-Tool', $call->definition->name);

        if ($call->clientName !== null) {
            $request->headers->set('X-Mcp-Client', $call->clientName);
        }

        $this->forwardAuthorization($request, $outer);
        $this->forwardHeaders($request, $outer);
        $this->setDocumentedHeaders($request, $call);
        $this->attachUploads($request, $call);

        // Application code can detect an agent call without forking a
        // controller: request()->attributes->get('mcp.tool').
        $request->attributes->set('mcp.tool', $call->definition->name);
        $request->attributes->set('mcp.source', $call->definition->source);

        return $request;
    }

    /**
     * The scheme and host the sub-request is made on, so a URL the route
     * builds for the agent points at the application rather than at
     * localhost.
     *
     * A request this process received over HTTP always carries a Host
     * header. One captured in a console (`mcp-bridge:call`, a stdio server)
     * never does, and its host would be the placeholder localhost, so
     * `app.url` stands in.
     */
    protected function base(?Request $outer): string
    {
        if ($outer === null || ! $outer->headers->has('host')) {
            return rtrim($this->config->string('app.url', ''), '/');
        }

        return $outer->getSchemeAndHttpHost();
    }

    protected function outer(): ?Request
    {
        return $this->app->bound('request') ? $this->app->make('request') : null;
    }

    /**
     * Headers the API documentation declares as parameters, and that the
     * application opted into exposing. The reserved ones this executor sets
     * itself are never overwritten from tool input.
     */
    protected function setDocumentedHeaders(Request $request, ToolCall $call): void
    {
        foreach ($call->definition->input->fields as $field) {
            if ($field->wire?->in !== WireIn::Header || $field->hidden) {
                continue;
            }

            $value = $call->argument($field->path);
            $name = $field->wire->name;

            if (! is_scalar($value) || in_array(strtolower($name), self::RESERVED, true)) {
                continue;
            }

            $request->headers->set($name, (string) $value);
        }
    }

    /**
     * The caller was already authenticated by the server's own middleware, so
     * the sub-request hands the resolved user over rather than authenticating
     * a second time. The route's middleware still runs and still decides.
     */
    protected function handoff(ToolCall $call): void
    {
        $guards = array_values(array_filter(
            $this->config->array('mcp-bridge.security.guards', []),
            'is_string',
        ));

        foreach ([...$guards, null] as $guard) {
            try {
                $this->app->make('auth')->guard($guard)->setUser($call->actor);
            } catch (Throwable) {
                // A guard named in config but not defined by the application.
            }
        }
    }

    /**
     * Staged files, attached where a browser upload would have put them, so
     * the FormRequest's own `file`, `image`, `mimes` and `dimensions` rules
     * run unchanged. An id that is not this caller's, or has expired, simply
     * does not arrive, and the application's `required` rule says so.
     */
    protected function attachUploads(Request $request, ToolCall $call): void
    {
        foreach ($this->wire->uploads($call) as $path => $id) {
            $file = $this->uploads->claim($id, $call->actor);

            if ($file !== null) {
                $request->files->set($path, $file);
            }
        }
    }

    /**
     * Pass the caller's own credential to the sub-request, so a route behind
     * a guard this package does not configure still authenticates.
     *
     * Not under `service-account`. There the call is deliberately not the
     * caller's, and forwarding their bearer would let a guard outside
     * `security.guards` resolve them anyway: the controller would run as the
     * caller while the gate, the policies and the audit all named the service
     * account. One actor per call, or the trail is fiction.
     */
    protected function forwardAuthorization(Request $request, ?Request $outer): void
    {
        if ($this->config->string('mcp-bridge.security.auth', 'passthrough') === 'service-account') {
            return;
        }

        $authorization = $outer?->headers->get('Authorization');

        if (is_string($authorization)) {
            $request->headers->set('Authorization', $authorization);
        }
    }

    /**
     * Headers the outer request carried that the route may read: the
     * language the client asked for, what it identifies as. An allowlist
     * rather than everything, because the outer request is the MCP call and
     * most of what it carries describes that call rather than this one.
     */
    protected function forwardHeaders(Request $request, ?Request $outer): void
    {
        if ($outer === null) {
            return;
        }

        foreach ($this->config->array('mcp-bridge.execution.forward_headers', []) as $name) {
            if (! is_string($name) || in_array(strtolower($name), self::RESERVED, true)) {
                continue;
            }

            $value = $outer->headers->get($name);

            // Set even when the sub-request already has one: a request made
            // from nothing carries Symfony's placeholder Accept-Language and
            // User-Agent, and the client's are the ones the route should see.
            if (is_string($value)) {
                $request->headers->set($name, $value);
            }
        }
    }

    protected function result(Response $response): SourceResult
    {
        $status = $response->getStatusCode();

        if ($response->isRedirection()) {
            $location = $response->headers->get('Location');

            return SourceResult::failed($status, [
                'message' => 'The route redirected to ['.(is_string($location) ? $location : 'elsewhere').'].',
            ]);
        }

        $content = (string) $response->getContent();

        if ($content === '') {
            return new SourceResult(status: $status, payload: null);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return new SourceResult(
                status: $status < 400 ? 500 : $status,
                payload: ['message' => 'The route did not return JSON.'],
                meta: ['code' => 'MCP_BRIDGE_NON_JSON'],
            );
        }

        return new SourceResult(
            status: $status,
            payload: $this->withoutModelNames($decoded),
            meta: array_filter(['retry_after' => $response->headers->get('Retry-After')]),
        );
    }

    /**
     * The exception handler renders a missing model as "No query results for
     * model [App\Models\Course] 12", and the class name is the application's
     * business rather than the agent's. The rest of a 404 message is kept,
     * since a route that aborts with its own words meant them.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function withoutModelNames(array $payload): array
    {
        $message = $payload['message'] ?? null;

        if (is_string($message) && str_starts_with($message, 'No query results for model')) {
            $payload['message'] = 'Not found.';
        }

        return $payload;
    }
}
