<?php

namespace App\Http\Controllers;

use App\Models\StudioProfile;
use Illuminate\Http\Request;

class StudioProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = StudioProfile::current();
        $profile->update($data);

        return $profile;
    }
}
