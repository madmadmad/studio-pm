<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A link shared in a message -- the frontend names and labels it from the
// URL (Components/LinkChip.jsx).
class MessageLink extends Model
{
    protected $fillable = ['message_id', 'url'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
