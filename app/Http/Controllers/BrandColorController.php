<?php

namespace App\Http\Controllers;

use App\Support\BrandPalette;
use Illuminate\Http\Request;

// A person's own color for the app (Profile), like their Appearance: it
// only ever changes the signed-in person's row. Null goes back to the
// studio red. Answers with the palette worked out from it, so the page
// can switch at once.
class BrandColorController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate(['brand_color' => ['present', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $color = $data['brand_color'] ? strtoupper($data['brand_color']) : null;

        $request->user()->forceFill(['brand_color' => $color])->save();

        return ['brand_color' => $color, 'palette' => BrandPalette::for($color)];
    }
}
