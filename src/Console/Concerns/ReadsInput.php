<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Console\Concerns;

use Illuminate\Console\Command;

/**
 * Console input, read as the type the command expects.
 *
 * An option is `array|bool|string|null` as far as the input layer knows, since
 * the same accessor serves `--flag`, `--name=x` and `--tag=a --tag=b`. Casting
 * that blindly is how a repeated option becomes the string "Array", so each
 * command says which shape it wanted.
 *
 * @phpstan-require-extends Command
 */
trait ReadsInput
{
    protected function stringOption(string $name, ?string $default = null): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    protected function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * A comma separated list option, where an empty value is an empty list
     * and only an absent option falls back to the default. `--scopes=` is
     * someone writing down "none", and reading it as "every" is the one
     * misreading a credential cannot afford.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function listOption(string $name, array $default): array
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== ''));
    }

    protected function stringArgument(string $name, string $default = ''): string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
