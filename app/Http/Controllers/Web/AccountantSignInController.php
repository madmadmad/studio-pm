<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountantSignIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// The accountant signs in by an emailed link (App\Services\AccountantSignIn),
// asked for on the sign-in page. The reply never says whether the email
// belongs to anyone, so it can't be used to find out who has access.
class AccountantSignInController extends Controller
{
    public function request(Request $request, AccountantSignIn $signIn)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', strtolower(trim($data['email'])))
            ->where('role', User::ROLE_ACCOUNTANT)
            ->whereNull('deactivated_at')
            ->first();

        if ($user) {
            $signIn->send($user);
        }

        return back()->with('status', "If that's the accountant's email, a sign-in link is on its way. It works for 20 minutes.");
    }

    public function verify(Request $request, User $user, string $token, AccountantSignIn $signIn)
    {
        if (! $signIn->redeem($user, $token)) {
            return redirect()->route('login')->with('status', 'That sign-in link has expired or has already been used. Ask for a new one below.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/bookkeeping');
    }
}
