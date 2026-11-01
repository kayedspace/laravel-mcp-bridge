<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * What an API documentation package already declares about an endpoint.
 *
 * A team that documents its API with dedoc/scramble or Scribe has written
 * down, in code, exactly what an agent needs: each parameter's name, type,
 * format, description, whether it is required, its default and an example.
 * Reading those declarations means no new annotations and no second place to
 * maintain, and because the OpenAPI document and the tool schema come from one
 * declaration they cannot drift apart.
 */
final readonly class Documented
{
    /**
     * @param  bool  $excluded  the route is deliberately kept out of the public docs, which makes it a poor
     *                          candidate for an agent too
     * @param  list<string>  $hidden  field paths the documentation hides
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $group = null,
        public bool $excluded = false,
        public InputSchema $input = new InputSchema,
        public array $hidden = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->description === null
            && $this->group === null
            && ! $this->excluded
            && $this->input->isEmpty()
            && $this->hidden === [];
    }

    /** Layer a more authoritative reading over this one. Stated values win. */
    public function mergedWith(self $other): self
    {
        return new self(
            title: $other->title ?? $this->title,
            description: $other->description ?? $this->description,
            group: $other->group ?? $this->group,
            excluded: $other->excluded || $this->excluded,
            input: $this->input->merge($other->input),
            hidden: array_values(array_unique([...$this->hidden, ...$other->hidden])),
        );
    }
}
