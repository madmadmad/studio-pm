<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A stretch of the books (a month, a tax year). Once locked -- after the
// CPA has the numbers -- nothing can be posted with a date inside it.
class AccountingPeriod extends Model
{
    protected $fillable = ['starts_on', 'ends_on', 'locked_at', 'locked_by'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'locked_at' => 'datetime',
    ];

    // The locked period a date falls in, if any.
    public static function lockedOn(DateTimeInterface|string $date): ?self
    {
        $day = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        return static::whereNotNull('locked_at')
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)
            ->first();
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
