<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

// The demo app has no login screen, and the token panel needs a signed-in
// user. A local-only shortcut, so the panel can be opened and screenshotted
// without building one; the environment check is what keeps it a demo.
Route::get('/login-as/{user}', function (User $user): RedirectResponse {
    abort_unless(app()->environment('local'), 404);

    Auth::login($user);

    return redirect('/mcp-bridge');
})->middleware('web');
