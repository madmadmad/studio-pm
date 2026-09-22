<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;

class MessageAttachmentController extends Controller
{
    use ServesPrivateFile;

    public function show(Request $request, MessageAttachment $attachment)
    {
        abort_unless($request->user()->canAccessProject($attachment->message->project), 403);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->path, $attachment->original_name);
    }

    public function thumbnail(Request $request, MessageAttachment $attachment)
    {
        abort_unless($request->user()->canAccessProject($attachment->message->project), 403);

        abort_unless($attachment->hasThumbnail(), 404);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->thumbnail_path);
    }
}
