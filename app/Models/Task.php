<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = ['project_id', 'schedule_item_id', 'title', 'assignee', 'description', 'status', 'due_date', 'visible_to_client'];

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

    // The schedule item (phase) the task is part of, if any.
    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(ScheduleItem::class);
    }

    // Who has starred it (TaskFavoriteController).
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_favorites')->withTimestamps();
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
