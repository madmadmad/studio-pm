<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Carbon;

// Eloquent's built-in 'datetime' cast serializes/deserializes using PHP's
// default timezone -- this app's config('app.timezone'), America/New_York,
// a deliberate choice for date-only fields elsewhere. For a genuine
// point-in-time column (a scheduled send time), that silently shifts the
// instant by the UTC/Eastern offset on every read, since the stored string
// carries no timezone of its own. This cast pins storage and retrieval to
// UTC explicitly, independent of the app's default timezone, so a value
// always round-trips to the exact same instant.
class UtcDateTime implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC')->setTimezone('UTC');
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
