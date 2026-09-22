<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Shared by every controller that serves a file from the private disk
// (message attachments, avatars): redirect to a short-lived signed URL on
// s3 (production), or stream directly on the local disk (development,
// where Flysystem's local adapter has no concept of a temporary URL at
// all). Callers are responsible for authorizing the request first --
// this trait only decides *how* to hand back bytes the caller already
// approved.
trait ServesPrivateFile
{
    protected function respondWithPrivateFile(string $disk, string $path, ?string $downloadName = null): StreamedResponse|RedirectResponse
    {
        if (config("filesystems.disks.{$disk}.driver") === 's3') {
            return redirect(Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(5)));
        }

        return Storage::disk($disk)->response($path, $downloadName);
    }
}
