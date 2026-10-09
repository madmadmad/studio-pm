<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// One message in a Chat conversation. The body is plain text -- never HTML
// -- with each @mention stored as a <@user_id> token, so a mention still
// shows the right name after someone's renamed. The front end escapes it
// and turns mentions, links and line breaks into elements on render.
class ChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = ['conversation_id', 'user_id', 'body', 'gif', 'edited_at'];

    protected $casts = [
        'edited_at' => 'datetime',
        // { id, title, url, width, height } from GIPHY (App\Services\Giphy).
        'gif' => 'array',
    ];

    public const MENTION_PATTERN = '/<@(\d+)>/';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_message_mentions', 'message_id', 'user_id')->withTimestamps();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }

    public function isAuthor(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    // The user ids a body's <@id> tokens name, each once.
    public static function mentionedIds(?string $body): array
    {
        preg_match_all(self::MENTION_PATTERN, (string) $body, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    // Everything a message is shown with.
    public static function displayRelations(): array
    {
        return ['user:id,name,avatar_path', 'attachments', 'reactions.user:id,name', 'mentions:id'];
    }

    // What the front end gets, over HTTP and the socket alike. A deleted
    // message keeps its place in the history but nothing of what it said.
    public function toChatArray(?string $clientId = null): array
    {
        $deleted = $this->trashed();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'client_id' => $clientId,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'avatar_url' => $this->user->avatar_url] : null,
            'body' => $deleted ? null : $this->body,
            'gif' => $deleted ? null : $this->gif,
            'mention_ids' => $deleted ? [] : $this->mentions->pluck('id')->all(),
            'attachments' => $deleted ? [] : $this->attachments->map->toChatArray()->all(),
            'reactions' => $deleted ? [] : ChatReaction::grouped($this->reactions),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'deleted' => $deleted,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
