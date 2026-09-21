<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tax extends Model
{
    protected $fillable = ['name', 'rate'];

    protected $casts = [
        'rate' => 'decimal:2',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
