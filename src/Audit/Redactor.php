<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Audit;

use Illuminate\Config\Repository;

/**
 * Decides which keys are secrets, and takes them out.
 *
 * One matcher for both directions, because they have to agree: a field masked
 * in the audit and passed through to the agent is still leaked, and the two
 * rules drifting apart is exactly how that happens.
 *
 * Keys are matched loosely, because the field is as likely to be called
 * `new_password` or `api_token` as `password`.
 */
class Redactor
{
    public function __construct(protected Repository $config) {}

    /**
     * What is written down: the key stays, the value does not. An audit trail
     * is read by more people than the application is and lives longer, so a
     * password an agent happened to send must not survive in it, while the
     * fact that one was sent is worth keeping.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    public function mask(array $values): array
    {
        $masked = [];

        foreach ($values as $key => $value) {
            $name = (string) $key;

            $masked[$name] = match (true) {
                $this->isSecret($name) => '[redacted]',
                is_array($value) => $this->mask($value),
                default => $value,
            };
        }

        return $masked;
    }

    /**
     * What an agent is shown: the key goes too, since naming a secret an
     * agent cannot have only invites it to ask for it another way.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function remove(array $values): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSecret($key)) {
                continue;
            }

            $kept[$key] = is_array($value) ? $this->remove($value) : $value;
        }

        return $kept;
    }

    public function isSecret(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->secrets() as $secret) {
            if (str_contains($key, strtolower($secret))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    protected function secrets(): array
    {
        return array_values(array_filter(
            $this->config->array('mcp-bridge.security.redact', []),
            'is_string',
        ));
    }
}
