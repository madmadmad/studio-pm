<?php

namespace App\Http\Controllers;

use App\Models\User;
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

    // Their position and bio, for proposals' team sections.
    public function updateBio(Request $request)
    {
        $data = $request->validate([
            'job_title' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:20000'],
        ]);

        $user = $request->user();
        $user->update(['job_title' => $data['job_title'] ?? null, 'bio' => User::cleanBio($data['bio'] ?? null)]);

        return $user->fresh();
    }

    public function updateBioPhoto(Request $request)
    {
        $request->validate(['photo' => ['required', 'image', 'max:8192']]);

        return $request->user()->replaceBioPhoto($request->file('photo'));
    }

    public function destroyBioPhoto(Request $request)
    {
        return $request->user()->replaceBioPhoto(null);
    }
}
