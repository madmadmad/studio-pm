<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A team member's connected Google account (App\Services\GoogleCalendarClient):
// the tokens never leave the server, and the calendars they chose to show.
class GoogleCalendarAccount extends Model
{
    protected $fillable = ['user_id', 'google_email', 'access_token', 'refresh_token', 'token_expires_at', 'calendars'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'calendars' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
