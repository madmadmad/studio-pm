<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageParticipant extends Model
{
    protected $fillable = ['message_id', 'user_id', 'contact_id', 'joined_at', 'notified_at', 'last_read_at'];

    protected $casts = [
        'joined_at' => 'datetime',
        'notified_at' => 'datetime',
        'last_read_at' => 'datetime',
    ];

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

    // Whichever of user/contact this row actually represents.
    public function actor(): User|Contact|null
    {
        return $this->user ?? $this->contact;
    }

    public function isActor(User|Contact $actor): bool
    {
        return $actor instanceof User
            ? $this->user_id === $actor->id
            : $this->contact_id === $actor->id;
    }
}
