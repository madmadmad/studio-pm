<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    protected $fillable = [
        'message_id', 'disk', 'path', 'original_name', 'mime_type', 'size',
        'width', 'height', 'thumbnail_path', 'thumbnail_status',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    // The frontend builds the actual download/thumbnail URLs itself (they
    // differ between the staff and portal API prefixes -- see MessagesPanel's
    // `endpoints` prop), so this only exposes what it can't derive on its own.
    protected $appends = ['is_image'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isImage(): bool
    {
        $extension = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return in_array($extension, config('message_attachments.image_extensions'), true);
    }

    protected function getIsImageAttribute(): bool
    {
        return $this->isImage();
    }

    // Only true once the queued thumbnail job has actually produced one --
    // callers show a placeholder in the meantime rather than a broken image.
    public function hasThumbnail(): bool
    {
        return $this->thumbnail_status === 'ready' && $this->thumbnail_path !== null;
    }

    // Null on the local disk (dev): the download controller streams the file
    // itself instead, since Flysystem's local adapter has no concept of a
    // temporary URL at all. On s3 (prod) this returns a short-lived signed
    // URL so the file is never served from a plain public path.
    public function temporaryUrl(?string $path = null): ?string
    {
        if (config("filesystems.disks.{$this->disk}.driver") !== 's3') {
            return null;
        }

        return Storage::disk($this->disk)->temporaryUrl($path ?? $this->path, now()->addMinutes(5));
    }
}
