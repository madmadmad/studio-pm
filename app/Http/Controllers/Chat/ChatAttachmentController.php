<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;

// A Chat file, for the people in its conversation only: streamed from the
// local disk in development, a short-lived signed URL on S3/R2.
class ChatAttachmentController extends Controller
{
    use ServesPrivateFile;

    public function show(ChatAttachment $attachment)
    {
        $this->authorize('view', $attachment->message->conversation);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->path, $attachment->original_name);
    }

    public function thumbnail(ChatAttachment $attachment)
    {
        $this->authorize('view', $attachment->message->conversation);
        abort_unless($attachment->hasThumbnail(), 404);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->thumbnail_path);
    }

    // What the lightbox shows: the WebP copy of a big image, else the original.
    public function display(ChatAttachment $attachment)
    {
        $this->authorize('view', $attachment->message->conversation);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->display_path ?? $attachment->path);
    }
}
