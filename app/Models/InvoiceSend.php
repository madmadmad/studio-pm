<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSend extends Model
{
    const TYPE_EMAIL = 'email';

    const TYPE_LINK = 'link';

    const TYPE_REMINDER = 'reminder';

    const STATUS_SCHEDULED = 'scheduled';

    const STATUS_QUEUED = 'queued';

    const STATUS_SENT = 'sent';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'invoice_id', 'type', 'reminder_rule', 'status', 'scheduled_for', 'sent_at',
        'sent_by_user_id', 'recipients', 'cc', 'subject', 'message', 'failure_reason',
    ];

    protected $casts = [
        'scheduled_for' => UtcDateTime::class,
        'sent_at' => UtcDateTime::class,
        'recipients' => 'array',
        'cc' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
