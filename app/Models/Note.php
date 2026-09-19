<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    protected $fillable = ['project_id', 'title', 'body'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
