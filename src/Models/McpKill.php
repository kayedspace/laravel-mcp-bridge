<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Models;

use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Models\Concerns\OnBridgeConnection;

/**
 * One thing switched off: a tool name, `source:command`, `server:ops` or `*`.
 *
 * @property string $target
 */
class McpKill extends Model
{
    use OnBridgeConnection;

    public const UPDATED_AT = null;

    protected $primaryKey = 'target';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function bridgeTable(): string
    {
        return 'kills';
    }
}
