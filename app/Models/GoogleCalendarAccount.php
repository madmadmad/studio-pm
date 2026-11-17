<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A team member's connected Google account (App\Services\GoogleCalendarClient):
// the tokens never leave the server, and the calendars they chose to show.
class GoogleCalendarAccount extends Model
{
    protected $fillable = ['user_id', 'google_email', 'access_token', 'refresh_token', 'token_expires_at', 'calendars', 'default_calendar_id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'calendars' => 'array',
    ];

    // Where new events go: their chosen default while it's still one they
    // show and can edit, else the first that is.
    public function defaultCalendarId(): ?string
    {
        $editable = collect($this->calendars ?? [])->filter(fn ($c) => $c['can_edit'] ?? false);

        return $editable->firstWhere('id', $this->default_calendar_id)['id'] ?? $editable->first()['id'] ?? null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
