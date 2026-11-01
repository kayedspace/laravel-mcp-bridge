<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Stands in for a Sanctum access token: another table's token, with its own ids. */
class AccessToken extends Model
{
    protected $guarded = [];
}
