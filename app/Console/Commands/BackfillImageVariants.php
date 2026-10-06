<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAttachmentThumbnail;
use App\Models\Contact;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\AvatarProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

// One-off (safe to rerun) catch-up for images stored before the WebP
// variants existed: the small copy of each avatar and bio photo, and a
// WebP thumbnail (plus lightbox copy, when it's big) of each message
// image. Anything already done is skipped. Until it runs, the app falls back to the
// full-size files, so nothing is broken in the meantime.
class BackfillImageVariants extends Command
{
    protected $signature = 'images:backfill';

    protected $description = 'Create the small photo copies and WebP message-image variants missing from older uploads.';

    public function handle(): void
    {
        $disk = Storage::disk(config('filesystems.private_disk'));
        $photos = 0;

        $kinds = [
            [User::class, 'avatar_path', AvatarProcessor::SMALL_DIMENSION],
            [Contact::class, 'avatar_path', AvatarProcessor::SMALL_DIMENSION],
            [User::class, 'bio_photo_path', AvatarProcessor::BIO_PHOTO_SMALL_WIDTH],
        ];
        foreach ($kinds as [$model, $column, $smallWidth]) {
            $model::query()->whereNotNull($column)->each(function ($owner) use ($disk, $column, $smallWidth, &$photos) {
                $path = $owner->{$column};
                if ($disk->exists(AvatarProcessor::smallPath($path))) {
                    return;
                }
                try {
                    AvatarProcessor::storeSmallCopy($path, $smallWidth);
                    $photos++;
                } catch (Throwable $e) {
                    $this->warn("Skipped the photo at {$path}: {$e->getMessage()}");
                }
            });
        }

        $displayMax = config('message_attachments.display_max_dimension');
        $attachments = 0;

        MessageAttachment::query()
            ->whereIn('thumbnail_status', ['ready', 'failed'])
            ->where(fn ($q) => $q
                ->where('thumbnail_path', 'not like', '%.webp')
                ->orWhereNull('thumbnail_path')
                ->orWhere(fn ($q) => $q
                    ->whereNull('display_path')
                    ->where('original_name', 'not like', '%.gif') // never gets one; see the job
                    ->where(fn ($q) => $q->where('width', '>', $displayMax)->orWhere('height', '>', $displayMax))))
            // By id, not offset: each row drops out of this query once done.
            ->lazyById()
            ->each(function (MessageAttachment $attachment) use (&$attachments) {
                GenerateAttachmentThumbnail::dispatchSync($attachment);
                $attachments++;
            });

        $this->info("Small copies made for {$photos} photo(s); {$attachments} message image(s) processed.");
    }
}
