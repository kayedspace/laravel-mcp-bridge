<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * This package's tables: prefixed, and on their own connection when the
 * application put them there.
 *
 * Both answers come from configuration rather than being written into the
 * models, so a team that keeps audit data on a separate database says so once.
 *
 * @phpstan-require-extends Model
 */
trait OnBridgeConnection
{
    abstract protected function bridgeTable(): string;

    public function getTable(): string
    {
        return config()->string('mcp-bridge.database.table_prefix', 'mcp_bridge_').$this->bridgeTable();
    }

    public function getConnectionName(): ?string
    {
        $connection = config('mcp-bridge.database.connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : parent::getConnectionName();
    }
}
