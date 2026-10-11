<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    // Time someone may see: all of it with All projects, otherwise time on
    // projects they're on or have been (read-only past work).
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasPermission('all_projects')
            ? $query
            : $query->whereHas('project', fn ($q) => $q->visibleTo($user, current: false));
    }

    // Billable time not yet on an invoice -- what the Dashboard counts as
    // unbilled, and what InvoiceController::store bills (marking it billed).
    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->where('billable', true)->where('billed', false);
    }

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
