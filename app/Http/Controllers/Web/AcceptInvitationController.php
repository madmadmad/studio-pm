<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $user = $this->findByToken($request->query('email'), $token);

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'email' => $request->query('email'),
            'valid' => $user !== null,
        ]);
    }

    public function store(Request $request, string $token)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::default()],
        ]);

        $user = $this->findByToken($data['email'], $token);
        abort_unless($user, 422, 'This invite link is invalid or has expired.');

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'invite_token' => null,
            'invite_expires_at' => null,
            'email_verified_at' => now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    protected function findByToken(?string $email, string $token): ?User
    {
        if (! $email) {
            return null;
        }

        $user = User::where('email', $email)->whereNotNull('invite_token')->first();

        if (! $user || ! hash_equals($user->invite_token, hash('sha256', $token))) {
            return null;
        }

        if ($user->invite_expires_at?->isPast()) {
            return null;
        }

        return $user;
    }
}
