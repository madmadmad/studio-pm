<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Profile: the signed-in person's own account (photo, details, password,
// color, appearance) and security (two-factor, passkeys). Their work --
// tasks, projects, time -- is My Work (MyWorkPageController).
class ProfilePageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Index', [
            'profileUser' => $user,
            // Security: two-factor (required) and passkeys (optional).
            'security' => fn () => [
                'twoFactor' => $user->two_factor_confirmed_at !== null,
                'twoFactorSince' => $user->two_factor_confirmed_at?->toIso8601String(),
                'passkeys' => $user->passkeys()->orderBy('created_at')->get(['id', 'name', 'last_used_at', 'created_at']),
            ],
            // What a new password needs (AppServiceProvider's
            // Password::defaults), said under the field.
            'passwordHint' => app()->isProduction()
                ? 'At least 12 characters, with upper and lower case letters, a number and a symbol.'
                : 'At least 8 characters.',
        ]);
    }
}
