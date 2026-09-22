<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

// Shared by the staff and portal profile controllers -- an avatar is
// cropped square and resized synchronously (small, fast, no need for the
// queued job the larger message-attachment thumbnails use).
class AvatarProcessor
{
    const DIMENSION = 400;

    public static function store(UploadedFile $file): string
    {
        $manager = new ImageManager(Driver::class);
        $image = $manager->decodeBinary(file_get_contents($file->getRealPath()));
        $image->cover(self::DIMENSION, self::DIMENSION);

        $path = 'avatars/'.Str::random(40).'.jpg';
        Storage::disk(config('filesystems.private_disk'))->put($path, (string) $image->encode(new JpegEncoder(quality: 85)));

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(config('filesystems.private_disk'))->delete($path);
        }
    }
}
