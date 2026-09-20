<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioProfile extends Model
{
    protected $fillable = ['name', 'address', 'email', 'phone', 'website', 'payment_instructions'];

    // Singleton -- the app only ever has one studio to represent, so there's
    // always exactly one row rather than a set the user picks from.
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
