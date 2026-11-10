<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One line on a person's own to-do list (My Work → To-do), in one of their
// groups or none. Private: only its owner ever sees or changes it
// (TodoController).
class Todo extends Model
{
    protected $fillable = ['title', 'url', 'position', 'starred', 'done_at', 'todo_category_id'];

    protected $casts = [
        'starred' => 'boolean',
        'done_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TodoCategory::class, 'todo_category_id');
    }
}
