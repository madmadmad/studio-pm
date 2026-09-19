<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subtask extends Model
{
    protected $fillable = ['task_id', 'title', 'assignee', 'status', 'position'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
