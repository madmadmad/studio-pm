<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One line of a project's schedule: a titled date range (a phase or
// milestone), drawn as a bar on the Schedule tab's gantt chart. A one-day
// item has the same start and end date. `position` is its place in the
// project's hand-ordered list. `service_id` is the service it was built
// from, if any; the project's tasks can sit in it as its phase.
class ScheduleItem extends Model
{
    protected $fillable = ['project_id', 'service_id', 'title', 'description', 'starts_on', 'ends_on', 'position'];

    protected $casts = [
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
