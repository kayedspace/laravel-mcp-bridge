<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Execution\OpenApiExecutor;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use Symfony\Component\Yaml\Yaml;

/** @param array<string, mixed> $settings */
function compileOpenApi(array $settings = []): CompileResult
{
    config()->set('mcp-bridge.sources.openapi.enabled', true);
    config()->set('mcp-bridge.sources.openapi.documents.billing', [
        'spec' => __DIR__.'/../Fixtures/openapi/billing.json',
        'token' => 'secret',
        'scopes' => ['billing:read'],
        'abilities' => ['use-billing'],
        ...$settings,
    ]);

    return app(ManifestCompiler::class)->compile();
}

/** @param array<string, mixed> $arguments */
function callOpenApi(string $tool, array $arguments = [], array $settings = []): SourceResult
{
    return app(OpenApiExecutor::class)->execute(callTool(
        toolNamed($tool, compileOpenApi($settings)),
        $arguments,
        new TokenUser(['billing:read'], 9),
    ));
}

beforeEach(function (): void {
    app(Gate::class)->define('use-billing', fn (): bool => true);
});

describe('discovery', function (): void {
    it('exposes every operation, named by its operationId under the document prefix', function (): void {
        expect(compileOpenApi()->names())->toContain(
            'billing_list_invoices',
            'billing_create_invoice',
            'billing_get_invoice',
            'billing_void_invoice',
        );
    });

    it('refuses an operation whose body an agent cannot send', function (): void {
        $result = compileOpenApi();

        expect($result->names())->not->toContain('billing_upload_report')
            ->and($result->refusals['openapi:billing:POST /reports'][0]->message)
            ->toContain('not JSON')
            ->toContain('multipart/form-data');
    });

    it('carries this application authority, and is refused without it', function (): void {
        expect(toolNamed('billing_list_invoices', compileOpenApi())->authority->scopes)->toBe(['billing:read']);

        $result = compileOpenApi(['scopes' => [], 'abilities' => []]);

        expect($result->names())->not->toContain('billing_list_invoices')
            ->and($result->refusals['openapi:billing:GET /invoices'][0]->message)
            ->toContain('needs both #[McpScope] and #[McpAbility]');
    });

    it('infers safety from the verb, as a route does', function (): void {
        $result = compileOpenApi();

        expect(toolNamed('billing_list_invoices', $result)->safety->readOnly)->toBeTrue()
            ->and(toolNamed('billing_void_invoice', $result)->safety->destructive)->toBeTrue()
            ->and(toolNamed('billing_void_invoice', $result)->authority->confirm)->toBeTrue()
            ->and(toolNamed('billing_create_invoice', $result)->safety->destructive)->toBeFalse();
    });

    it('narrows with only and except, by operationId or by method and path', function (): void {
        expect(compileOpenApi(['only' => ['listInvoices']])->names())->toBe(['billing_list_invoices'])
            ->and(compileOpenApi(['only' => ['DELETE /invoices/{id}']])->names())->toBe(['billing_void_invoice'])
            ->and(compileOpenApi(['except' => ['voidInvoice']])->names())->not->toContain('billing_void_invoice')
            ->and(compileOpenApi(['only' => []])->names())->not->toContain('billing_list_invoices');
    });

    it('lands on the operations server, beside the remote tools', function (): void {
        expect(toolNamed('billing_list_invoices', compileOpenApi())->server)->toBe('ops');
    });

    it('yields no tools from a document it cannot read, and keeps the rest of the surface', function (): void {
        config()->set('mcp-bridge.mode', 'opt-in');
        defineApiRoutes();

        $names = compileOpenApi(['spec' => __DIR__.'/../Fixtures/openapi/missing.json'])->names();

        expect($names)->not->toContain('billing_list_invoices')
            ->and($names)->toContain('list_courses');
    });
});

describe('the schema', function (): void {
    it('reads query and path parameters', function (): void {
        $result = compileOpenApi();

        $list = toolNamed('billing_list_invoices', $result)->input;
        $get = toolNamed('billing_get_invoice', $result)->input;

        expect($list->get('status')?->enum)->toBe(['open', 'paid'])
            ->and($list->get('status')?->description)->toBe('Only invoices in this state.')
            ->and($list->get('status')?->required)->toBeFalse()
            ->and($list->get('page')?->type->value)->toBe('integer')
            ->and($list->get('page')?->min)->toBe(1)
            // Declared on the path item, inherited by every operation under
            // it, and required because the URL has a hole in it.
            ->and($get->get('id')?->required)->toBeTrue()
            ->and($get->get('id')?->type->value)->toBe('integer');
    });

    it('reads the JSON body with its references and allOf folded', function (): void {
        $input = toolNamed('billing_create_invoice', compileOpenApi())->input;

        expect($input->get('customer_id')?->required)->toBeTrue()
            ->and($input->get('currency')?->default)->toBe('EUR')
            ->and($input->get('lines')?->type->value)->toBe('array')
            ->and($input->get('lines.*.amount')?->type->value)->toBe('number')
            ->and($input->get('lines.*.description')?->required)->toBeTrue();
    });

    it('reads a YAML document when a parser is installed', function (): void {
        $path = sys_get_temp_dir().'/mcp-bridge-billing.yaml';

        file_put_contents($path, Yaml::dump(json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/openapi/billing.json'),
            true,
        ), 8));

        expect(compileOpenApi(['spec' => $path])->names())->toContain('billing_list_invoices');

        unlink($path);
    })->skip(! class_exists(Yaml::class), 'symfony/yaml is not installed');
});

describe('calling', function (): void {
    it('sends the call to the document server with the API credential, never the agent one', function (): void {
        Http::fake(['https://billing.test/api/*' => Http::response(['data' => [['id' => 1]]])]);

        $result = callOpenApi('billing_list_invoices', ['status' => 'open']);

        expect($result->status)->toBe(200)
            ->and($result->payload['data'][0]['id'])->toBe(1);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://billing.test/api/invoices?status=open'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request->hasHeader('X-Mcp-Tool', 'billing_list_invoices'));
    });

    it('fills the path and sends a JSON body', function (): void {
        Http::fake(['https://billing.test/api/*' => Http::response(['id' => 7], 201)]);

        callOpenApi('billing_create_invoice', ['customer_id' => 3, 'lines' => [['description' => 'Seat', 'amount' => 9.5]]]);
        callOpenApi('billing_void_invoice', ['id' => 5]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://billing.test/api/invoices'
            && $request['customer_id'] === 3
            && $request['lines'][0]['amount'] === 9.5);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://billing.test/api/invoices/5');
    });

    it('prefers a configured base url over the document server', function (): void {
        Http::fake(['https://staging.billing.test/*' => Http::response([])]);

        callOpenApi('billing_list_invoices', [], ['base_url' => 'https://staging.billing.test/v2/']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://staging.billing.test/v2/invoices');
    });

    it('reports an API that cannot be reached', function (): void {
        Http::fake(fn () => throw new ConnectionException('down'));

        $down = callOpenApi('billing_list_invoices');

        expect($down->status)->toBe(502)
            ->and($down->meta['code'])->toBe('MCP_BRIDGE_REMOTE_UNAVAILABLE');
    });

    it('refuses an answer that is not JSON', function (): void {
        Http::fake(['https://billing.test/api/*' => Http::response('<html>', 200)]);

        expect(callOpenApi('billing_list_invoices')->meta['code'])->toBe('MCP_BRIDGE_NON_JSON');
    });

    it('passes the status the API answered with', function (): void {
        Http::fake(['https://billing.test/api/*' => Http::response(['message' => 'No such invoice.'], 404)]);

        $result = callOpenApi('billing_get_invoice', ['id' => 404]);

        expect($result->status)->toBe(404)
            ->and($result->payload['message'])->toBe('No such invoice.');
    });
});

it('drops a hidden field rather than sending this application\'s user id to the API', function (): void {
    config()->set('mcp-bridge.security.fill_from_auth', ['customer_id']);
    Http::fake(['https://billing.test/api/*' => Http::response(['id' => 7], 201)]);

    callOpenApi('billing_create_invoice', ['customer_id' => 3, 'lines' => [['description' => 'Seat', 'amount' => 9.5]]]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && ! array_key_exists('customer_id', $request->data()));
});
