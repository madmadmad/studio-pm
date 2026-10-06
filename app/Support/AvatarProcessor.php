<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

// Shared by the staff and portal profile controllers -- an avatar is
// cropped square and resized synchronously (small, fast, no need for the
// queued job the larger message-attachment thumbnails use).
class AvatarProcessor
{
    const DIMENSION = 400;

    // Each photo also gets a small copy, which the frontend picks via
    // srcset where it fits. Avatars mostly show at 20-48px, so 96px covers
    // those on retina screens and the full 400px one is only fetched for
    // the big ones.
    const SMALL_DIMENSION = 96;

    // A bio photo is a portrait (4:5) shown large and printed in proposal
    // PDFs, so it's kept bigger: 800 x 1000.
    const BIO_PHOTO_WIDTH = 800;

    const BIO_PHOTO_HEIGHT = 1000;

    // On screen a bio photo is 180px wide (the proposal's team section).
    const BIO_PHOTO_SMALL_WIDTH = 400;

    // Cropped to fill `width` x `height` (square unless a height is given),
    // saved as WebP -- roughly a third smaller than a JPEG of the same
    // quality, and dompdf reads it for the proposal PDF too.
    public static function store(UploadedFile $file, int $width = self::DIMENSION, string $folder = 'avatars', ?int $height = null, int $smallWidth = self::SMALL_DIMENSION): string
    {
        $manager = new ImageManager(Driver::class);
        $image = $manager->decodeBinary(file_get_contents($file->getRealPath()));
        $image->cover($width, $height ?? $width);

        $path = $folder.'/'.Str::random(40).'.webp';
        $disk = Storage::disk(config('filesystems.private_disk'));
        $disk->put($path, (string) $image->encode(new WebpEncoder(quality: 82)));

        $image->scaleDown(width: $smallWidth);
        $disk->put(self::smallPath($path), (string) $image->encode(new WebpEncoder(quality: 82)));

        return $path;
    }

    // The small copy of a photo. Older photos may not have one until
    // images:backfill runs; callers fall back to $path.
    public static function smallPath(string $path): string
    {
        return preg_replace('/\.[^.]+$/', '', $path).'-sm.webp';
    }

    // Makes the small copy for a photo stored before there was one (it's
    // already cropped, so this only scales).
    public static function storeSmallCopy(string $path, int $smallWidth = self::SMALL_DIMENSION): void
    {
        $disk = Storage::disk(config('filesystems.private_disk'));
        $image = (new ImageManager(Driver::class))->decodeBinary($disk->get($path));
        $image->scaleDown(width: $smallWidth);
        $disk->put(self::smallPath($path), (string) $image->encode(new WebpEncoder(quality: 82)));
    }

    // A short fingerprint of the stored path for the image's URL (?v=...):
    // every upload gets a new random path, so a new photo gets a new URL
    // and the browser can cache each one as immutable.
    public static function version(string $path): string
    {
        return substr(md5($path), 0, 8);
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(config('filesystems.private_disk'))->delete([$path, self::smallPath($path)]);
        }
    }
}
