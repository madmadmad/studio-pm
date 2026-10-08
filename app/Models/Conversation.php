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

    protected $fillable = ['type', 'name', 'slug', 'description', 'direct_key', 'created_by'];

    // Who's in it, with how far each has read.
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['last_read_message_id', 'joined_at', 'muted'])
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

    public function scopeChannels(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CHANNEL);
    }

    // The same set of people always makes the same key, in any order.
    public static function directKey(array $userIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids);

        return implode(':', $ids);
    }
}
