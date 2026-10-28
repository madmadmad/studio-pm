<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One pay run entered from its Cash Requirement Summary
// (App\Services\Payroll\CashRequirementSummary): the PDF, kept on the
// private disk, and the journal entry it posted.
class PayrollRun extends Model
{
    protected $fillable = ['check_date', 'period_date', 'journal_entry_id', 'report_path', 'report_filename', 'summary', 'created_by'];

    protected $casts = [
        'check_date' => 'date',
        'period_date' => 'date',
        'summary' => 'array',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    // The report itself, through the app (it's on the private disk).
    public function reportUrl(): string
    {
        return route('api.payroll-runs.report', $this);
    }
}
