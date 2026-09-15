<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $fillable = ['company_id', 'name', 'email', 'phone', 'role', 'is_primary', 'is_billing'];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_billing' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
