<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Setting up two-factor, which every staff member must have
// (RequireTwoFactor): where they're sent until it's done. After that it's
// managed from Profile.
class SecurityPageController extends Controller
{
    public function setup(Request $request)
    {
        if ($request->user()->two_factor_confirmed_at) {
            return redirect('/');
        }

        return Inertia::render('Security/Setup');
    }
}
