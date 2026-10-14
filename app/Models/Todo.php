<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One line on a person's own to-do list (My Work → To-do). Private: only
// its owner ever sees or changes it (TodoController).
class Todo extends Model
{
    protected $fillable = ['title', 'position', 'done_at'];

    protected $casts = [
        'done_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
