<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Uploads;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Models\McpUpload;
use KayedSpace\McpBridge\Support\Duration;
use RuntimeException;

/**
 * Where a staged file lives between being uploaded and being used.
 *
 * Two steps rather than one: the agent stages a file and gets an id, then
 * passes that id to whichever tool wants it. Base64 inside a tool's arguments
 * would spend the agent's context on bytes it cannot read and break outright
 * on anything large.
 *
 * A staged file belongs to whoever staged it. One agent must not be able to
 * attach another's upload to its own call by guessing an id, so claiming
 * checks the owner and not merely the id.
 */
class UploadStore
{
    public function __construct(
        protected FilesystemFactory $filesystem,
        protected Repository $config,
    ) {}

    /** The file an agent sent, decoded and put somewhere a tool can reach it. */
    public function stage(string $name, string $base64, Authenticatable $actor): McpUpload
    {
        $contents = base64_decode($base64, strict: true);

        if ($contents === false) {
            throw new RuntimeException('The file contents are not valid base64.');
        }

        $max = $this->config->integer('mcp-bridge.uploads.max_kb', 10240) * 1024;

        if (strlen($contents) > $max) {
            throw new RuntimeException(
                'That file is larger than this application accepts, which is '.
                round($max / 1024 / 1024, 1).' MB.'
            );
        }

        $disk = $this->config->string('mcp-bridge.uploads.disk', 'local');
        $id = (string) Str::uuid();
        $path = 'mcp-bridge/uploads/'.$id.'.'.$this->extension($name);

        $this->filesystem->disk($disk)->put($path, $contents);

        $upload = new McpUpload;
        $upload->forceFill([
            'id' => $id,
            'uploader_type' => $actor::class,
            'uploader_id' => $actor->getAuthIdentifier(),
            'disk' => $disk,
            'path' => $path,
            'name' => $name,
            'mime_type' => $this->mimeType($disk, $path),
            'size' => strlen($contents),
            'expires_at' => Date::now()->addSeconds(
                Duration::seconds($this->config->get('mcp-bridge.uploads.ttl'), 86400),
            ),
        ]);
        $upload->save();

        return $upload;
    }

    /**
     * The staged file as an UploadedFile, so the application's own `file`,
     * `image`, `mimes` and `dimensions` rules run exactly as they would for a
     * browser upload.
     */
    public function claim(string $id, Authenticatable $actor): ?UploadedFile
    {
        $upload = McpUpload::query()->find($id);

        if ($upload === null || $upload->hasExpired() || ! $this->belongsTo($upload, $actor)) {
            return null;
        }

        $disk = $this->filesystem->disk($upload->disk);
        $path = $disk->path($upload->path);

        if (! is_file($path)) {
            return null;
        }

        $upload->forceFill(['claimed_at' => Date::now()])->saveQuietly();

        // test: true, because the file is already on disk and PHP never saw an
        // HTTP upload for it; without it isValid() fails on a real path.
        return new UploadedFile($path, $upload->name, $upload->mime_type, test: true);
    }

    protected function belongsTo(McpUpload $upload, Authenticatable $actor): bool
    {
        $owner = $upload->getAttribute('uploader_id');
        $caller = $actor->getAuthIdentifier();

        return $upload->getAttribute('uploader_type') === $actor::class
            && is_scalar($owner)
            && is_scalar($caller)
            && (string) $owner === (string) $caller;
    }

    protected function mimeType(string $disk, string $path): ?string
    {
        $mime = $this->filesystem->disk($disk)->mimeType($path);

        return is_string($mime) ? $mime : null;
    }

    protected function extension(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return $extension === '' ? 'bin' : strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? 'bin');
    }
}
