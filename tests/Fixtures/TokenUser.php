<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

/**
 * An actor that can report its token abilities, the way a Sanctum or Passport
 * user does.
 */
class TokenUser extends TestUser
{
    /** @param list<string> $abilities */
    public function __construct(
        protected array $abilities = [],
        int $id = 1,
    ) {
        parent::__construct($id);
    }

    public function tokenCan(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }
}
