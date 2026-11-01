<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use KayedSpace\McpBridge\Models\Concerns\OnBridgeConnection;

/**
 * A file an agent staged, waiting to be used by a tool call.
 *
 * Staged rather than sent inline because base64 inside tool arguments spends
 * the agent's context on bytes it cannot read, and breaks outright on
 * anything large. The agent uploads once, gets an id, and passes the id.
 *
 * @property string $id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string|null $mime_type
 * @property int $size
 * @property Carbon|null $claimed_at
 * @property Carbon|null $expires_at
 */
class McpUpload extends Model
{
    use HasUuids;
    use OnBridgeConnection;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'claimed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected function bridgeTable(): string
    {
        return 'uploads';
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
