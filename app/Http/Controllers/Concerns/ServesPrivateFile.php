<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Shared by every controller that serves a file from the private disk
// (message attachments, avatars): redirect to a signed URL on s3
// (production), or stream directly on the local disk (development, where
// Flysystem's local adapter has no concept of a temporary URL at all).
// Callers are responsible for authorizing the request first -- this trait
// only decides *how* to hand back bytes the caller already approved.
//
// Everything served here is immutable per URL (attachments never change,
// and avatar/bio photo URLs carry a version of the stored path), so the
// browser is told to keep it for a day. On s3 the signature is pinned to
// the start of the day, so the signed URL -- and the browser's cached copy
// of it -- is the same for every request that day instead of a fresh URL
// (and a fresh download) on every page load.
trait ServesPrivateFile
{
    protected function respondWithPrivateFile(string $disk, string $path, ?string $downloadName = null): StreamedResponse|RedirectResponse
    {
        $cacheControl = 'private, max-age=86400';

        if (config("filesystems.disks.{$disk}.driver") === 's3') {
            $signedAt = Carbon::today();

            return redirect($this->signedS3Url($disk, $path, $signedAt, $cacheControl))
                // The redirect itself lasts until midnight; the signed URL it
                // points at stays valid a full day past that.
                ->header('Cache-Control', 'private, max-age='.max(0, (int) now()->diffInSeconds($signedAt->copy()->addDay())));
        }

        return Storage::disk($disk)->response($path, $downloadName, ['Cache-Control' => $cacheControl]);
    }

    // Presigned by hand rather than via temporaryUrl(), which has no way to
    // pass the signing time without also sending it as a GetObject param.
    private function signedS3Url(string $disk, string $path, Carbon $signedAt, string $cacheControl): string
    {
        $storage = Storage::disk($disk);
        $client = $storage->getClient();

        $command = $client->getCommand('GetObject', [
            'Bucket' => $storage->getConfig()['bucket'],
            'Key' => $storage->path($path),
            'ResponseCacheControl' => $cacheControl,
        ]);

        return (string) $client->createPresignedRequest($command, $signedAt->copy()->addDays(2), ['start_time' => $signedAt])->getUri();
    }
}
