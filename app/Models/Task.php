<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = ['project_id', 'title', 'assignee', 'description', 'status', 'due_date', 'visible_to_client'];

    protected $casts = [
        'due_date' => 'date',
        'visible_to_client' => 'boolean',
    ];

    // "Show client" is on: the task appears in the Client Hub.
    public function scopeVisibleToClient($query)
    {
        return $query->where('visible_to_client', true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('position');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TaskFile::class)->latest();
    }
}
