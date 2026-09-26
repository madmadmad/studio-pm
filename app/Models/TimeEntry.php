<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'task_id', 'service_id', 'user_id',
        'date', 'hours', 'note', 'billable', 'billed', 'invoice_item_id',
    ];

    protected $casts = [
        'date' => 'date',
        'billable' => 'boolean',
        'billed' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // The team member the time belongs to (who did the work).
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // The service the time was for; its billable setting decides whether
    // the entry is billable when it's picked.
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}
