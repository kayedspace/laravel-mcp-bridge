<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Query;

use Illuminate\Config\Repository;

/**
 * The query string names and delimiter Spatie is configured with, so the
 * schema an agent reads and the query string the executor builds cannot
 * disagree with the package that parses them.
 *
 * Read from Spatie's own config file, which is present whether or not Spatie
 * is installed, since the defaults are this package's defaults too.
 */
final readonly class QueryParameters
{
    public function __construct(private Repository $config) {}

    public function filter(): string
    {
        return $this->name('filter');
    }

    public function sort(): string
    {
        return $this->name('sort');
    }

    public function include(): string
    {
        return $this->name('include');
    }

    public function fields(): string
    {
        return $this->name('fields');
    }

    public function name(string $parameter): string
    {
        $configured = $this->config->get("query-builder.parameters.{$parameter}");

        return is_string($configured) && $configured !== '' ? $configured : $parameter;
    }

    /**
     * A dotted field path as the query name it travels under: `filter.status`
     * is `filter[status]`, and a wildcard member is a list, `tags[]`.
     */
    public function bracket(string $path): string
    {
        $segments = explode('.', $path);
        $name = array_shift($segments);

        foreach ($segments as $segment) {
            $name .= $segment === '*' ? '[]' : "[{$segment}]";
        }

        return $name;
    }

    /** @return non-empty-string */
    public function delimiter(): string
    {
        $configured = $this->config->get('query-builder.delimiter');

        return is_string($configured) && $configured !== '' ? $configured : ',';
    }

    /**
     * One query value as the list it stands for: `a,b` is two values, and so
     * is an array that arrived as `sort[]=a&sort[]=b`.
     *
     * @return list<string>
     */
    public function values(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
                $raw,
            ), fn (string $value): bool => $value !== ''));
        }

        if (! is_scalar($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode($this->delimiter(), (string) $raw)),
            fn (string $value): bool => $value !== '',
        ));
    }
}
