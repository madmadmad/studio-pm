<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\Request;

// Avatars carry no sensitive business data (unlike message attachments),
// so the bar here is simply "signed in as someone" rather than scoped to a
// specific project -- any authenticated staff user or portal contact can
// view any avatar. Checking both guards explicitly (rather than relying on
// $request->user()'s default-guard resolution) since these routes aren't
// nested under either guard's own middleware group.
class AvatarController extends Controller
{
    use ServesPrivateFile;

    public function user(Request $request, User $user)
    {
        abort_unless($request->user('web') || $request->user('client'), 403);

        abort_unless($user->avatar_path, 404);

        return $this->respondWithPrivateFile(config('filesystems.private_disk'), $user->avatar_path);
    }

    public function contact(Request $request, Contact $contact)
    {
        abort_unless($request->user('web') || $request->user('client'), 403);

        abort_unless($contact->avatar_path, 404);

        return $this->respondWithPrivateFile(config('filesystems.private_disk'), $contact->avatar_path);
    }
}
