<?php

declare(strict_types=1);

use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use KayedSpace\McpBridge\Models\McpAuditEntry;
use KayedSpace\McpBridge\Models\McpUpload;
use KayedSpace\McpBridge\Server\UploadTool;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use KayedSpace\McpBridge\Uploads\UploadStore;
use Laravel\Mcp\Request;

function stage(string $name = 'cover.txt', string $contents = 'a cover', ?TestUser $as = null): McpUpload
{
    return app(UploadStore::class)->stage($name, base64_encode($contents), $as ?? new TestUser);
}

beforeEach(function (): void {
    Storage::fake('local');

    Schema::create('mcp_bridge_uploads', function ($table): void {
        $table->uuid('id')->primary();
        $table->nullableMorphs('uploader');
        $table->string('disk');
        $table->string('path');
        $table->string('name');
        $table->string('mime_type')->nullable();
        $table->unsignedBigInteger('size')->default(0);
        $table->timestamp('claimed_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    defineApiRoutes();
});

describe('staging', function (): void {
    it('stores the file and hands back an id', function (): void {
        $upload = stage('cover.txt', 'a cover');

        expect($upload->id)->toBeString()
            ->and($upload->size)->toBe(7)
            ->and($upload->expires_at)->not->toBeNull();

        Storage::disk('local')->assertExists($upload->path);
    });

    it('refuses a file larger than the application accepts', function (): void {
        config()->set('mcp-bridge.uploads.max_kb', 1);

        expect(fn () => stage('big.txt', str_repeat('x', 2048)))
            ->toThrow(RuntimeException::class, 'larger than this application accepts');
    });

    it('refuses contents that are not base64 at all', function (): void {
        expect(fn () => app(UploadStore::class)->stage('cover.txt', 'not base64!!', new TestUser))
            ->toThrow(RuntimeException::class, 'not valid base64');
    });
});

describe('claiming', function (): void {
    it('turns an id back into a real file', function (): void {
        $upload = stage('cover.txt', 'a cover');

        $file = app(UploadStore::class)->claim($upload->id, new TestUser);

        expect($file?->getClientOriginalName())->toBe('cover.txt')
            ->and($file?->isValid())->toBeTrue()
            ->and($upload->fresh()?->claimed_at)->not->toBeNull();
    });

    it('will not hand one agent the file another staged', function (): void {
        $upload = stage(as: new TestUser(1));

        expect(app(UploadStore::class)->claim($upload->id, new TestUser(2)))->toBeNull();
    });

    it('will not hand back one that expired', function (): void {
        $upload = stage();
        $upload->forceFill(['expires_at' => Date::now()->subMinute()])->save();

        expect(app(UploadStore::class)->claim($upload->id, new TestUser))->toBeNull();
    });

    it('answers an id that was never staged with nothing', function (): void {
        expect(app(UploadStore::class)->claim('00000000-0000-0000-0000-000000000000', new TestUser))->toBeNull();
    });
});

describe('the schema', function (): void {
    it('asks for an upload id rather than a file', function (): void {
        $field = toolNamed('cover_course')->input->get('cover');

        expect($field?->type->value)->toBe('string')
            ->and($field?->format)->toBe('mcp-upload')
            ->and($field?->description)->toContain('upload_file')
            // The application's own limits stay in the description, so an
            // agent picks a file that will pass rather than one that will not.
            ->and($field?->unexpressible)->toContain('mimes:txt,png');
    });
});

describe('calling a tool with a staged file', function (): void {
    it('arrives as a real upload, so the file rules run', function (): void {
        $upload = stage('cover.txt', 'a cover');

        $result = $this->callMcpTool('cover_course', [
            'cover' => $upload->id,
            'caption' => 'The cover',
        ], as: new TestUser);

        expect($result->isError)->toBeFalse()
            ->and($result->structured['data'])->toMatchArray([
                'name' => 'cover.txt',
                'size' => 7,
                'caption' => 'The cover',
            ]);
    });

    it('fails the application own validation when nothing arrives', function (): void {
        $result = $this->callMcpTool('cover_course', [
            'cover' => '00000000-0000-0000-0000-000000000000',
        ], as: new TestUser);

        expect($result->isError)->toBeTrue()
            ->and($result->text)->toContain('cover');
    });
});

describe('pruning', function (): void {
    it('deletes the row and the bytes behind it', function (): void {
        $kept = stage('recent.txt');
        $old = stage('old.txt');
        $old->forceFill(['created_at' => Date::now()->subDays(3)])->save();

        $this->artisan('mcp-bridge:prune-uploads --older-than=24h')->assertSuccessful();

        expect(McpUpload::query()->count())->toBe(1)
            ->and(McpUpload::query()->first()?->name)->toBe('recent.txt');

        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($kept->path);
    });
});

it('records a staged upload in the audit trail, without the file in it', function (): void {
    createAuditTable();

    test()->actingAs(new TestUser, 'mcp-bridge');

    app(UploadTool::class)->handle(new Request([
        'name' => 'cover.txt',
        'contents' => base64_encode('a cover'),
    ]));

    // The audit is written after a response, and calling the tool directly
    // ends no request, so flush what it deferred.
    app(DeferredCallbackCollection::class)->invoke();

    $entry = McpAuditEntry::query()->first();

    expect($entry?->tool)->toBe('upload_file')
        ->and($entry?->getAttribute('source'))->toBe('upload')
        // The whole point: a name and a size, never the bytes.
        ->and($entry?->arguments)->toBe(['name' => 'cover.txt', 'size' => 7]);
});
