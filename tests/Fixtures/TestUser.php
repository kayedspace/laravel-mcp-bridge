<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** An actor for tool calls that needs no database behind it. */
class TestUser extends User
{
    protected $table = 'users';

    public function __construct(int $id = 1)
    {
        parent::__construct();

        $this->forceFill(['id' => $id, 'name' => 'Test Actor']);

        $this->exists = true;
    }
}
