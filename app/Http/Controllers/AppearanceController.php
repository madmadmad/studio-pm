<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// A person's Appearance setting -- dark, light or system -- on their own
// profile, staff (/api/profile/appearance) and client (/api/portal/...)
// alike: it only ever changes the signed-in person's own row.
class AppearanceController extends Controller
{
    public const THEMES = ['dark', 'light', 'system'];

    public function update(Request $request)
    {
        $data = $request->validate(['theme' => ['required', Rule::in(self::THEMES)]]);

        $request->user()->forceFill(['theme' => $data['theme']])->save();

        return ['theme' => $data['theme']];
    }
}
