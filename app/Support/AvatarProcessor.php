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

    // A bio photo is a portrait (4:5) shown large and printed in proposal
    // PDFs, so it's kept bigger: 800 x 1000.
    const BIO_PHOTO_WIDTH = 800;

    const BIO_PHOTO_HEIGHT = 1000;

    // Cropped to fill `width` x `height` (square unless a height is given),
    // saved as WebP -- roughly a third smaller than a JPEG of the same
    // quality, and dompdf reads it for the proposal PDF too.
    public static function store(UploadedFile $file, int $width = self::DIMENSION, string $folder = 'avatars', ?int $height = null): string
    {
        $manager = new ImageManager(Driver::class);
        $image = $manager->decodeBinary(file_get_contents($file->getRealPath()));
        $image->cover($width, $height ?? $width);

        $path = $folder.'/'.Str::random(40).'.webp';
        Storage::disk(config('filesystems.private_disk'))->put($path, (string) $image->encode(new WebpEncoder(quality: 82)));

        return $path;
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
            Storage::disk(config('filesystems.private_disk'))->delete($path);
        }
    }
}
