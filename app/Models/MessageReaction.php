<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One person's emoji reaction on a message: a staff user or a client
// contact (exactly one of the two is set), as with a message's sender.
class MessageReaction extends Model
{
    // The reactions on offer. A short, fixed set, so the chips under a
    // message stay tidy; emoji can still go anywhere in a message's text.
    public const EMOJI = ['👍', '❤️', '😂', '🎉', '👀', '🙏', '✅', '🔥'];

    protected $fillable = ['message_id', 'user_id', 'contact_id', 'emoji'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
