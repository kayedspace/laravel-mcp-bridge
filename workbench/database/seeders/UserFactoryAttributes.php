<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Support\Facades\Hash;

/** The one user the workbench needs, without reaching for a factory state. */
final class UserFactoryAttributes
{
    /** @return array<string, string> */
    public static function for(string $name): array
    {
        return [
            'name' => $name,
            'password' => Hash::make('password'),
        ];
    }
}
