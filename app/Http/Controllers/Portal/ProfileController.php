<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Support\AvatarProcessor;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function updateAvatar(Request $request)
    {
        $request->validate(['avatar' => ['required', 'image', 'max:5120']]);

        $contact = $request->user();
        $oldPath = $contact->avatar_path;

        $contact->update(['avatar_path' => AvatarProcessor::store($request->file('avatar'))]);
        AvatarProcessor::delete($oldPath);

        return $contact->fresh();
    }

    public function destroyAvatar(Request $request)
    {
        $contact = $request->user();
        AvatarProcessor::delete($contact->avatar_path);
        $contact->update(['avatar_path' => null]);

        return $contact->fresh();
    }
}
