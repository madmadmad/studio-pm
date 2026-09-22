<?php

namespace App\Http\Controllers;

use App\Support\AvatarProcessor;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function updateAvatar(Request $request)
    {
        $request->validate(['avatar' => ['required', 'image', 'max:5120']]);

        $user = $request->user();
        $oldPath = $user->avatar_path;

        $user->update(['avatar_path' => AvatarProcessor::store($request->file('avatar'))]);
        AvatarProcessor::delete($oldPath);

        return $user->fresh();
    }

    public function destroyAvatar(Request $request)
    {
        $user = $request->user();
        AvatarProcessor::delete($user->avatar_path);
        $user->update(['avatar_path' => null]);

        return $user->fresh();
    }
}
