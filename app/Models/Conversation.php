<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A Chat conversation: a channel any staff member can find and join, or a
// direct message between a fixed set of people. Staff only -- clients talk
// to the studio through project Messages instead.
class Conversation extends Model
{
    const TYPE_CHANNEL = 'channel';

    const TYPE_DIRECT = 'direct';

    protected $fillable = ['type', 'name', 'slug', 'description', 'emoji', 'direct_key', 'created_by'];

    // Who's in it, with how far each has read.
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['last_read_message_id', 'joined_at', 'muted', 'hidden_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isChannel(): bool
    {
        return $this->type === self::TYPE_CHANNEL;
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public function hasMember(User $user): bool
    {
        return $this->relationLoaded('members')
            ? $this->members->contains('id', $user->id)
            : $this->members()->whereKey($user->id)->exists();
    }

    // The conversations this person is in.
    public function scopeForMember(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn ($q) => $q->whereKey($user->id));
    }

    // The ones in this person's sidebar: all of theirs but the direct
    // messages they've closed.
    public function scopeShownTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn ($q) => $q->whereKey($user->id)->whereNull('conversation_user.hidden_at'));
    }

    public function scopeChannels(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CHANNEL);
    }

    // How the Chat sidebar shows it, with this person's unread counts
    // (ChatUnread). A direct message is named by its people, so it carries
    // them; a channel carries its name. Both carry who's in them, by id.
    // Load members first.
    public function toSummaryArray(array $counts = []): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            // A channel's emoji, in place of its # (null: the #).
            'emoji' => $this->emoji,
            'members' => $this->isDirect()
                ? $this->members->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name, 'avatar_url' => $m->avatar_url, 'active' => $m->isActive()])->values()->all()
                : [],
            // Who can be @mentioned in it.
            'member_ids' => $this->members->pluck('id')->values()->all(),
            'member_count' => $this->members->count(),
            'unread' => $counts['unread'] ?? 0,
            'mentions' => $counts['mentions'] ?? 0,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    // The same set of people always makes the same key, in any order.
    public static function directKey(array $userIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids);

        return implode(':', $ids);
    }
}
