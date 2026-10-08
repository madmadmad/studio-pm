<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A file on a Chat message, on the chat attachments disk (config/chat.php)
// under a random path. Only ever served through ChatAttachmentController,
// which checks the person is in the conversation first.
class ChatAttachment extends Model
{
    protected $table = 'chat_message_attachments';

    protected $fillable = [
        'message_id', 'disk', 'path', 'original_name', 'mime_type', 'size',
        'width', 'height', 'thumbnail_path', 'display_path', 'thumbnail_status',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function isImage(): bool
    {
        $extension = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return in_array($extension, config('message_attachments.image_extensions'), true);
    }

    public function hasThumbnail(): bool
    {
        return $this->thumbnail_status === 'ready' && $this->thumbnail_path !== null;
    }

    // The original plus any thumbnail and lightbox-sized copy.
    public function storedPaths(): array
    {
        return array_values(array_filter([$this->path, $this->thumbnail_path, $this->display_path]));
    }

    public function toChatArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'is_image' => $this->isImage(),
            'url' => route('chat.attachments.show', $this),
            'display_url' => $this->isImage() ? route('chat.attachments.display', $this) : null,
            'thumbnail_url' => $this->hasThumbnail() ? route('chat.attachments.thumbnail', $this) : null,
        ];
    }
}
