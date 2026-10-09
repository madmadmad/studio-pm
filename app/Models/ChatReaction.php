<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

// One person's emoji on a Chat message -- any single emoji (App\Rules\SingleEmoji).
class ChatReaction extends Model
{
    protected $table = 'chat_message_reactions';

    protected $fillable = ['message_id', 'user_id', 'emoji'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // A message's reactions as chips: each emoji once, in the order first
    // used, with who reacted with it.
    public static function grouped(Collection $reactions): array
    {
        return $reactions->sortBy('id')->groupBy('emoji')
            ->map(fn (Collection $group, string $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'users' => $group->map(fn (self $r) => ['id' => $r->user_id, 'name' => $r->user?->name])->values()->all(),
            ])
            ->values()->all();
    }
}
