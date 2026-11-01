<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Output;

use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Audit\Redactor;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolDefinition;
use KayedSpace\McpBridge\Data\ToolResult;

/**
 * Compacts a successful response into something worth spending an agent's
 * context on: the data envelope unwrapped, long lists and strings trimmed,
 * pagination kept, and redacted keys removed.
 */
class OutputShaper
{
    public function __construct(
        protected Redactor $redactor,
        protected Repository $config,
    ) {}

    public function shape(SourceResult $result, ToolDefinition $definition): ToolResult
    {
        if ($result->status === 204 || $result->payload === null) {
            return ToolResult::ok($this->emptySummary($definition));
        }

        if (! is_array($result->payload)) {
            return ToolResult::ok(
                is_scalar($result->payload) ? $this->truncate((string) $result->payload) : 'The call succeeded.',
            );
        }

        $pagination = $this->pagination($result->payload);

        $trimmed = $this->fit($this->trim(
            $this->select($this->redactor->remove($this->unwrap($result->payload)), $definition),
            $definition->output->maxItems ?? $this->config->integer('mcp-bridge.output.max_items', 25),
        ));

        $structured = ['data' => $trimmed['value']];

        if ($pagination !== []) {
            $structured['pagination'] = $pagination;
        }

        $meta = $this->meta($result->payload);

        if ($meta !== []) {
            $structured['meta'] = $meta;
        }

        return ToolResult::ok(
            text: $this->text($this->summary($definition, $trimmed, $pagination), $structured),
            structured: $this->config->boolean('mcp-bridge.output.structured', true) ? $structured : null,
            meta: $trimmed['truncated'] ? ['truncated' => true] : [],
        );
    }

    /**
     * The summary, then the data as JSON.
     *
     * A client that reads only text content still gets the answer, which is
     * what the protocol asks of a tool that also sends structured content.
     * Without this, "Returned 3 of 3 items." was the whole of what such a
     * client saw, and turning `output.structured` off took the data away from
     * every client.
     *
     * @param  array<string, mixed>  $structured
     */
    protected function text(string $summary, array $structured): string
    {
        $json = json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? $summary : $summary."\n".$json;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function unwrap(array $payload): array
    {
        // A JsonResource wraps in `data`; a collection wraps a list in it too.
        while (array_keys($payload) === ['data'] && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        if (isset($payload['meta'], $payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function pagination(array $payload): array
    {
        $source = is_array($payload['meta'] ?? null) ? $payload['meta'] : $payload;

        $pagination = [];

        // Kept even while the rest of a paginator's noise is dropped.
        foreach (Pagination::keys() as $key) {
            $value = $source[$key] ?? null;

            if (is_scalar($value)) {
                $pagination[$key] = $value;
            }
        }

        return $pagination;
    }

    /**
     * Metadata the application attached on purpose, which is everything in
     * `meta` that is not paging.
     *
     * Unwrapping a response drops `meta` because a paginator's copy of it is
     * noise once the paging is read out of it. A JsonResource's
     * `->additional(['meta' => ...])` lands in the same key and is the
     * opposite: a developer deliberately saying something, and dropping it
     * silently loses the only part of the answer the code went out of its way
     * to add.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function meta(array $payload): array
    {
        $meta = $payload['meta'] ?? null;

        if (! is_array($meta)) {
            return [];
        }

        $extra = [];

        foreach ($meta as $key => $value) {
            if (is_string($key) && ! in_array($key, Pagination::keys(), true)) {
                $extra[$key] = $value;
            }
        }

        /** @var array<string, mixed> $kept */
        $kept = $this->redactor->remove($extra);

        return $kept;
    }

    /**
     * Keep only the fields #[McpOutput] asked for, on a record or on every
     * record of a list.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function select(array $data, ToolDefinition $definition): array
    {
        $fields = $definition->output->fields ?? [];

        if ($fields === []) {
            return $data;
        }

        if (! array_is_list($data)) {
            return Arr::only($data, $fields);
        }

        return array_map(
            fn (mixed $record): mixed => is_array($record) ? Arr::only($record, $fields) : $record,
            $data,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{value: mixed, count: int|null, truncated: bool}
     */
    protected function trim(array $data, int $maxItems): array
    {
        $isList = array_is_list($data);
        $count = $isList ? count($data) : null;
        $truncated = false;

        if ($isList && $count > $maxItems) {
            $data = array_slice($data, 0, $maxItems);
            $truncated = true;
        }

        return ['value' => $this->compact($data, $maxItems), 'count' => $count, 'truncated' => $truncated];
    }

    /**
     * Twenty-five rows within the item limit can still be far more context
     * than the answer is worth, because the limit counts rows and an agent
     * pays for bytes. A wide row is the case that gets away: the list looks
     * reasonable and fills the window.
     *
     * Only lists are cut. A single record over budget is returned whole,
     * since dropping half an object gives an agent something worse than a
     * large answer, which is a wrong one. Long strings inside it are already
     * capped by `max_string`.
     *
     * @param  array{value: mixed, count: int|null, truncated: bool}  $trimmed
     * @return array{value: mixed, count: int|null, truncated: bool}
     */
    protected function fit(array $trimmed): array
    {
        $budget = $this->config->integer('mcp-bridge.output.token_budget', 8000);
        $value = $trimmed['value'];

        if ($budget <= 0 || ! is_array($value) || ! array_is_list($value)) {
            return $trimmed;
        }

        // One row always survives. An empty list where rows exist reads as
        // "there are none", which is a wrong answer rather than a large one,
        // and the agent would narrow its filter and ask again for nothing.
        //
        // ponytail: re-encodes per dropped row, which is fine while max_items
        // keeps the list short. Measure the encoded size once per row if that
        // limit is ever raised past a few hundred.
        while (count($value) > 1 && $this->tokens($value) > $budget) {
            array_pop($value);
            $trimmed['truncated'] = true;
        }

        $trimmed['value'] = $value;

        return $trimmed;
    }

    /**
     * Four bytes to the token is the usual rough conversion. It does not need
     * to be exact: it decides when to stop filling an agent's context, and
     * being a little early costs a page while being late costs the window.
     */
    protected function tokens(mixed $value): int
    {
        return intdiv(strlen((string) json_encode($value)), 4);
    }

    protected function compact(mixed $value, int $maxItems): mixed
    {
        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value) && count($value) > $maxItems) {
            $value = array_slice($value, 0, $maxItems);
        }

        $dropNulls = $this->config->boolean('mcp-bridge.output.drop_nulls', true);
        $out = [];

        foreach ($value as $key => $item) {
            if ($dropNulls && $item === null) {
                continue;
            }

            $out[$key] = $this->compact($item, $maxItems);
        }

        return $out;
    }

    protected function truncate(string $value): string
    {
        return Str::limit($value, $this->config->integer('mcp-bridge.output.max_string', 2000));
    }

    /**
     * @param  array{value: mixed, count: int|null, truncated: bool}  $trimmed
     * @param  array<string, mixed>  $pagination
     */
    protected function summary(ToolDefinition $definition, array $trimmed, array $pagination): string
    {
        if ($trimmed['count'] === null) {
            return Str::headline($definition->name).' returned a record.';
        }

        $shown = is_array($trimmed['value']) ? count($trimmed['value']) : 0;
        $summary = "Returned {$shown} of {$trimmed['count']} items.";

        if (($pagination['has_more'] ?? false) === true || isset($pagination['next_cursor'])) {
            return $summary.' More pages are available.';
        }

        return $trimmed['truncated']
            ? $summary.' The list was trimmed; narrow it with filters or paging.'
            : $summary;
    }

    protected function emptySummary(ToolDefinition $definition): string
    {
        return $definition->safety->destructive
            ? Str::headline($definition->name).' completed.'
            : 'The call succeeded and returned no content.';
    }
}
