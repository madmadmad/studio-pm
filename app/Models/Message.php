<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Message extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'parent_id', 'sender_user_id', 'sender_contact_id', 'subject', 'body', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_id')->oldest('sent_at');
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sender_contact_id');
    }

    // Only meaningful on a root/thread message -- a reply doesn't carry its
    // own participant list, it belongs to its thread's.
    public function participants(): HasMany
    {
        return $this->hasMany(MessageParticipant::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function sender(): User|Contact|null
    {
        return $this->senderUser ?? $this->senderContact;
    }

    public function senderName(): ?string
    {
        return $this->sender()?->name;
    }

    public function isSender(User|Contact $actor): bool
    {
        return $actor instanceof User
            ? $this->sender_user_id === $actor->id
            : $this->sender_contact_id === $actor->id;
    }

    public function isParticipant(User|Contact $actor): bool
    {
        return $this->participants->contains(fn (MessageParticipant $p) => $p->isActor($actor));
    }

    // Used by the email notifications -- a message can be attachments-only
    // (no body text at all), so falls back to naming what was sent instead
    // of showing a blank line.
    public function snippet(int $length = 200): string
    {
        if (trim((string) $this->body)) {
            return Str::limit($this->body, $length);
        }

        $count = $this->attachments->count();

        return $count > 0 ? "Sent {$count} ".Str::plural('attachment', $count) : '';
    }
}
