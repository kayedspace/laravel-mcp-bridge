<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Data\Field;
use KayedSpace\McpBridge\Data\RouteTarget;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Enums\FieldType;
use KayedSpace\McpBridge\Enums\WireIn;
use KayedSpace\McpBridge\Uploads\Uploads;

/**
 * Turns an agent's arguments back into the shape the code behind a tool
 * expects: a query array, a JSON body, or console parameters.
 *
 * The field's dot path is the destination path, so `filter.status` becomes
 * `filter[status]=published`, which is what Spatie parses back. A wire
 * delimiter joins a list into one value, so `sort` leaves as
 * `sort=-created_at,title` rather than `sort[0]=`.
 *
 * Shared because the route executor and the model executor have to agree
 * exactly: a model list tool reaches the same query applier a route list tool
 * does, and a difference here would be a difference an agent could see.
 */
class WireSerializer
{
    /**
     * @return array<array-key, mixed>
     */
    public function query(ToolCall $call): array
    {
        return $this->collect($call, WireIn::Query);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function body(ToolCall $call): array
    {
        return $this->collect($call, WireIn::Body);
    }

    /**
     * A URI template with its path parameters filled in, and the optional
     * segments the agent did not supply dropped. Anything still unresolved
     * was required, and fails where the request lands, which is the same
     * answer a REST client would get.
     */
    public function path(string $template, ToolCall $call): string
    {
        foreach ($call->definition->input->fields as $field) {
            if ($field->wire?->in !== WireIn::Path) {
                continue;
            }

            $value = $call->argument($field->path);

            if ($value === null) {
                continue;
            }

            $template = str_replace(
                ['{'.$field->path.'}', '{'.$field->path.'?}'],
                rawurlencode((string) (is_scalar($value) ? $value : '')),
                $template,
            );
        }

        return (string) preg_replace('#/?\{[^}]+\?\}#', '', $template);
    }

    /**
     * The fields that carry a staged file rather than a value, as path => id.
     *
     * They are kept out of the body: the executor turns each id into a real
     * UploadedFile and attaches it where the application's own file rules
     * will find it.
     *
     * @return array<string, string>
     */
    public function uploads(ToolCall $call): array
    {
        $uploads = [];

        foreach ($call->definition->input->fields as $field) {
            if ($field->format !== Uploads::FORMAT || $field->hidden) {
                continue;
            }

            $value = $call->argument($field->path);

            if (is_string($value) && $value !== '') {
                $uploads[$field->path] = $value;
            }
        }

        return $uploads;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function collect(ToolCall $call, WireIn $in): array
    {
        $values = [];

        foreach ($call->definition->input->fields as $field) {
            if ($field->wire?->in !== $in) {
                continue;
            }

            // A confirmation twin is hidden from the agent and filled here,
            // because the application does not fill it: the request expects
            // it from whoever is calling.
            if ($field->filledFrom === 'confirmation') {
                $base = $call->argument((string) Str::beforeLast($field->path, '_confirmation'));

                if ($base !== null) {
                    $values[$field->wire->name] = $base;
                }

                continue;
            }

            // Filled from the actor, not from the agent and not from
            // nowhere: a route's controller usually sets this itself, and a
            // value it then overwrites costs nothing, while a body that
            // omits it entirely is how a non-nullable column fails. Only
            // where the request creates: on an update the field is the
            // record's owner, and the policy that allowed the update was
            // never asked about reassigning it.
            if ($field->filledFrom === 'auth') {
                $value = $this->creates($call) ? $call->filledFromActor() : null;

                if ($value !== null) {
                    $values[$field->wire->name] = $value;
                }

                continue;
            }

            // Every other hidden field is the application's to fill, never
            // the agent's.
            if ($field->hidden) {
                continue;
            }

            // A wildcard path types the members of an array; the array itself
            // is a field of its own, and it carries the value.
            if (str_contains($field->path, '*')) {
                continue;
            }

            // A staged file travels as a file, not as the id the agent sent.
            if ($field->format === Uploads::FORMAT) {
                continue;
            }

            $value = $call->argument($field->path);

            if ($value === null) {
                continue;
            }

            Arr::set($values, $field->path, $this->value($field, $this->bounded($field, $value)));
        }

        return $values;
    }

    protected function creates(ToolCall $call): bool
    {
        $target = $call->definition->target;

        return ! $target instanceof RouteTarget || $target->method === 'POST';
    }

    /**
     * The schema's maximum is advice until something holds a value to it.
     * An integer past it is brought back, so a list the tool caps at five
     * rows is fetched as five and the next page starts at six.
     */
    protected function bounded(Field $field, mixed $value): mixed
    {
        if ($field->type !== FieldType::Integer || $field->max === null || ! is_numeric($value)) {
            return $value;
        }

        return min((int) $value, (int) $field->max);
    }

    /**
     * A list that travels as one delimited value, which is how Spatie reads
     * `sort=-created_at,title` and `fields[courses]=id,title`.
     */
    public function value(Field $field, mixed $value): mixed
    {
        $delimiter = $field->wire?->delimiter;

        if ($delimiter === null || ! is_array($value)) {
            return $value;
        }

        return implode($delimiter, array_map(
            fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            $value,
        ));
    }
}
