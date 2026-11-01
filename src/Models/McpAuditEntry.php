<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Models\Concerns\OnBridgeConnection;

/**
 * A stored audit line.
 *
 * Append-only by intent: there is no update path, and `updated_at` does not
 * exist, because a record of what happened that can be edited is not a record.
 *
 * @property string $tool
 * @property string $outcome
 * @property string|null $token_type
 * @property string|null $token_id
 * @property-read string $credential
 * @property array<string, mixed> $arguments
 * @property Carbon|null $created_at
 */
class McpAuditEntry extends Model
{
    use OnBridgeConnection;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'confirmed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * What the call came with, for a person reading the trail: a package
     * token, another kind of token by its class, or none at all.
     *
     * @return Attribute<string, never>
     */
    protected function credential(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->token_type) {
            null => 'no token',
            (new McpToken)->getMorphClass() => 'package token',
            default => Str::headline(class_basename($this->token_type)),
        });
    }

    protected function bridgeTable(): string
    {
        return 'audit';
    }
}
