<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name', 'description', 'default_rate', 'unit', 'billable'];

    // Whether time logged against this service is billable (see
    // TimeEntryController, which copies it onto the entry).
    protected $casts = [
        'billable' => 'boolean',
    ];
}
