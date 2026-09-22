<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

// Runs off the request/response cycle so a large image upload never blocks
// sending the message -- the frontend shows a placeholder until
// thumbnail_status flips to 'ready' (or 'failed', in which case it just
// falls back to the full image).
class GenerateAttachmentThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public MessageAttachment $attachment) {}

    public function handle(): void
    {
        $disk = Storage::disk($this->attachment->disk);

        try {
            $manager = new ImageManager(Driver::class);
            $image = $manager->decodeBinary($disk->get($this->attachment->path));

            $max = config('message_attachments.thumbnail_max_dimension');
            $image->scaleDown(width: $max, height: $max);

            $thumbnailPath = preg_replace('/\.[^.]+$/', '', $this->attachment->path).'-thumb.jpg';
            $disk->put($thumbnailPath, (string) $image->encode(new JpegEncoder(quality: 80)));

            $this->attachment->update([
                'thumbnail_path' => $thumbnailPath,
                'thumbnail_status' => 'ready',
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to generate message attachment thumbnail', [
                'attachment_id' => $this->attachment->id,
                'error' => $e->getMessage(),
            ]);

            $this->attachment->update(['thumbnail_status' => 'failed']);
        }
    }
}
