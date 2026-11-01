<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Security;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Date;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Models\McpKill;
use Throwable;

/**
 * Turning a tool, a source, or the whole surface off without a deploy.
 *
 * In a table, because the moment this is needed is the moment a deploy is
 * the slowest possible answer, and because a switch that `cache:clear`
 * quietly turns back on is not a switch. Read through the cache for a few
 * seconds at a time, since every listing and every call asks, and a flush
 * then costs one query rather than every kill.
 *
 * Checked when listing and again when calling, like every other refusal
 * here: a client that cached a tool list a minute ago must not be able to
 * call something that has since been switched off.
 */
class KillSwitch
{
    public const ALL = '*';

    protected const KEY = 'mcp-bridge:killed';

    /** How long a worker may go without asking the table again. */
    protected const SECONDS = 10;

    public function __construct(protected Cache $cache) {}

    public function isKilled(ToolDefinition $definition): bool
    {
        $killed = $this->killed();

        if ($killed === []) {
            return false;
        }

        return in_array(self::ALL, $killed, true)
            || in_array($definition->name, $killed, true)
            || in_array("source:{$definition->source}", $killed, true)
            || in_array("server:{$definition->server}", $killed, true);
    }

    /** @param string $target a tool name, `source:command`, `server:ops`, or `*` */
    public function kill(string $target): void
    {
        McpKill::query()->firstOrCreate(['target' => $target], ['created_at' => Date::now()]);

        $this->cache->forget(self::KEY);
    }

    public function revive(string $target): void
    {
        McpKill::query()->whereKey($target)->delete();

        $this->cache->forget(self::KEY);
    }

    public function reviveAll(): void
    {
        McpKill::query()->delete();

        $this->cache->forget(self::KEY);
    }

    /**
     * What is switched off. A table that is not there yet answers nothing,
     * which `mcp-bridge:doctor` reports; the alternative is every call
     * failing because the switch's own storage is missing.
     *
     * Asked for before it is queried rather than caught afterwards, because
     * PostgreSQL aborts the surrounding transaction on a failed statement,
     * and a swallowed exception would still have poisoned every statement
     * the application ran after it.
     *
     * @return list<string>
     */
    public function killed(): array
    {
        try {
            $killed = $this->cache->remember(self::KEY, self::SECONDS, function (): array {
                $model = new McpKill;

                if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
                    return [];
                }

                return $model->newQuery()->orderBy('target')->pluck('target')->all();
            });
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter($killed, 'is_string'));
    }
}
