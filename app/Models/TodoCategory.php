<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One group on a person's own to-do list (My Work → To-do): their name
// for it, their color for it (a hex code, any they pick), and its place
// among their groups. Private like the to-dos (TodoCategoryController).
class TodoCategory extends Model
{
    // A hex color, as the color picker gives it.
    const COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    // What a new group starts as, the next in turn (the theme's chart
    // palette, so they sit well together until recolored).
    const DEFAULT_COLORS = ['#2F6FDB', '#1E9E80', '#C8841C', '#8A55C9', '#C9496F', '#4E9A3E', '#1E9BBE', '#6560D6', '#8A9097'];

    protected $fillable = ['name', 'color', 'position', 'collapsed'];

    protected $casts = [
        'collapsed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }
}
