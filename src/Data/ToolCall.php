<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;

/**
 * One call in flight. The actor is never null: a tool that cannot resolve a
 * real user does not run.
 */
final readonly class ToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public ToolDefinition $definition,
        public array $arguments,
        public Authenticatable $actor,
        public string $requestId,
        public ?string $clientName = null,
        public ?string $confirm = null,
        public ?string $idempotencyKey = null,
    ) {}

    public function argument(string $key, mixed $default = null): mixed
    {
        return data_get($this->arguments, $key, $default);
    }

    /**
     * What a field the application fills from the authenticated user is
     * filled with.
     *
     * Hiding `user_id` from the agent only works if something then sets it.
     * A route's controller does, which is why hiding was enough there; a
     * model write has no controller, so the field was hidden from the agent
     * and set by nobody, and a non-nullable column made the tool a
     * guaranteed failure.
     */
    public function filledFromActor(): int|string|null
    {
        $identifier = $this->actor->getAuthIdentifier();

        return is_int($identifier) || is_string($identifier) ? $identifier : null;
    }

    /**
     * The arguments the agent was entitled to send.
     *
     * A hidden field is not the agent's to set, and keeping it out of the
     * advertised schema is the courtesy rather than the enforcement: an agent
     * that sends `user_id` anyway, because it guessed or because it read the
     * REST documentation, must not have it forwarded. Every executor that
     * takes the agent's arguments takes these instead.
     *
     * @return array<string, mixed>
     */
    public function visibleArguments(): array
    {
        $arguments = $this->arguments;

        foreach ($this->definition->input->fields as $path => $field) {
            if (! $field->hidden) {
                continue;
            }

            // A field filled from the actor is handed over as the actor's
            // value, whatever the agent sent; every other hidden field is
            // simply not the agent's.
            $value = $field->filledFrom === 'auth' ? $this->filledFromActor() : null;

            if ($value === null) {
                Arr::forget($arguments, $path);
            } else {
                Arr::set($arguments, $path, $value);
            }
        }

        /** @var array<string, mixed> $arguments */
        return $arguments;
    }
}
