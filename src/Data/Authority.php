<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * The compiled answer to "who may call this". For non-HTTP sources the
 * compiler refuses to produce a definition at all unless both a scope and an
 * ability are declared, so an unauthorized tool never reaches the manifest.
 *
 * @phpstan-type AuthorityArray array{scopes: list<string>, abilities: list<string>, confirm: bool, confirm_message: string|null}
 */
final readonly class Authority
{
    /**
     * @param  list<string>  $scopes  token scopes, checked on list and on call
     * @param  list<string>  $abilities  Gate abilities, checked against the actor
     */
    public function __construct(
        public array $scopes = [],
        public array $abilities = [],
        public bool $confirm = false,
        public ?string $confirmMessage = null,
    ) {}

    public function isDeclared(): bool
    {
        return $this->scopes !== [] && $this->abilities !== [];
    }

    /** @return AuthorityArray */
    public function toArray(): array
    {
        return [
            'scopes' => $this->scopes,
            'abilities' => $this->abilities,
            'confirm' => $this->confirm,
            'confirm_message' => $this->confirmMessage,
        ];
    }

    /** @param AuthorityArray $data */
    public static function fromArray(array $data): self
    {
        return new self(
            scopes: $data['scopes'],
            abilities: $data['abilities'],
            confirm: $data['confirm'],
            confirmMessage: $data['confirm_message'],
        );
    }
}
