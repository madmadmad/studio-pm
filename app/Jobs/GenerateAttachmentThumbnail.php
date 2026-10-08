<?php

namespace App\Jobs;

use App\Models\ChatAttachment;
use App\Models\MessageAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

// Runs off the request/response cycle so a large image upload never blocks
// sending the message -- the frontend shows a placeholder until
// thumbnail_status flips to 'ready' (or 'failed', in which case it just
// falls back to the full image). A big image also gets a lightbox-sized
// WebP copy (display_path); smaller ones and GIFs (which may be animated)
// are shown in the lightbox as they are. Makes them for project message
// and Chat attachments alike (the two tables share these columns).
class GenerateAttachmentThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public MessageAttachment|ChatAttachment $attachment) {}

    public function handle(): void
    {
        $disk = Storage::disk($this->attachment->disk);

        try {
            $manager = new ImageManager(Driver::class);
            $image = $manager->decodeBinary($disk->get($this->attachment->path));

            $base = preg_replace('/\.[^.]+$/', '', $this->attachment->path);
            $old = [$this->attachment->thumbnail_path, $this->attachment->display_path];

            // Scaled down in two steps, largest first, so the thumbnail is
            // resampled from the smaller copy rather than the original.
            $displayPath = null;
            $displayMax = config('message_attachments.display_max_dimension');
            if (max($image->width(), $image->height()) > $displayMax && ! $this->isGif()) {
                $image->scaleDown(width: $displayMax, height: $displayMax);
                $displayPath = $base.'-display.webp';
                $disk->put($displayPath, (string) $image->encode(new WebpEncoder(quality: 82)));
            }

            $max = config('message_attachments.thumbnail_max_dimension');
            $image->scaleDown(width: $max, height: $max);

            $thumbnailPath = $base.'-thumb.webp';
            $disk->put($thumbnailPath, (string) $image->encode(new WebpEncoder(quality: 78)));

            $this->attachment->update([
                'thumbnail_path' => $thumbnailPath,
                'display_path' => $displayPath,
                'thumbnail_status' => 'ready',
            ]);

            // Regenerating (images:backfill) can leave an older JPEG thumbnail behind.
            $disk->delete(array_diff(array_filter($old), [$thumbnailPath, $displayPath]));
        } catch (Throwable $e) {
            Log::warning('Failed to generate message attachment thumbnail', [
                'attachment_id' => $this->attachment->id,
                'error' => $e->getMessage(),
            ]);

            $this->attachment->update(['thumbnail_status' => 'failed']);
        }
    }

    private function isGif(): bool
    {
        return strtolower(pathinfo($this->attachment->original_name, PATHINFO_EXTENSION)) === 'gif';
    }
}
