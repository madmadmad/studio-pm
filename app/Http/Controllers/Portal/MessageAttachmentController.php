<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Policies\Portal\MessagePolicy;
use Illuminate\Http\Request;

class MessageAttachmentController extends Controller
{
    use ServesPrivateFile;

    public function __construct(protected MessagePolicy $policy) {}

    public function show(Request $request, MessageAttachment $attachment)
    {
        abort_unless($this->policy->viewThread($request->user(), $attachment->message), 403);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->path, $attachment->original_name);
    }

    public function thumbnail(Request $request, MessageAttachment $attachment)
    {
        abort_unless($this->policy->viewThread($request->user(), $attachment->message), 403);

        abort_unless($attachment->hasThumbnail(), 404);

        return $this->respondWithPrivateFile($attachment->disk, $attachment->thumbnail_path);
    }
}
