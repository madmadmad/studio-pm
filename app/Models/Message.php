<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = [
        'project_id', 'parent_id', 'direction', 'sender_user_id', 'sender_contact_id',
        'to_email', 'from_email', 'subject', 'body', 'sent_at',
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
}
