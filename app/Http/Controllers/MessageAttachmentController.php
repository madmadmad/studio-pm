<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Models\MessageAttachment;

class MessageAttachmentController extends Controller
{
    use ServesPrivateFile;

    public function show(MessageAttachment $attachment)
    {
        $this->authorize('view', $attachment->message->project);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->path, $attachment->original_name);
    }

    public function thumbnail(MessageAttachment $attachment)
    {
        $this->authorize('view', $attachment->message->project);

        abort_unless($attachment->hasThumbnail(), 404);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->thumbnail_path);
    }
}
