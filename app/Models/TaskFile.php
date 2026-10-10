<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A file on a task, on the private disk (older uploads: the public one,
// per `disk`). Never a raw storage URL -- downloads go through
// TaskFileController (staff) or Portal\TaskFileController (a client, for a
// task shown to them), which check who's asking first.
class TaskFile extends Model
{
    protected $fillable = ['task_id', 'disk', 'path', 'filename', 'mime_type', 'size'];

    protected $hidden = ['disk', 'path'];

    protected $appends = ['url', 'portal_url'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function getUrlAttribute(): string
    {
        return route('task-files.show', $this);
    }

    public function getPortalUrlAttribute(): string
    {
        return route('portal.task-files.show', $this);
    }
}
