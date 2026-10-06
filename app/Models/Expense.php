<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Expense extends Model
{
    protected $fillable = [
        'name', 'amount', 'currency', 'category_id', 'project_id',
        'is_billable', 'markup_percent', 'tax_id', 'date',
        'is_recurring', 'recurrence_interval', 'receipt_path', 'receipt_filename',
        'source_label', 'plaid_transaction_id', 'billing_status',
        'invoice_id', 'invoice_item_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'is_billable' => 'boolean',
        'is_recurring' => 'boolean',
        'date' => 'date',
    ];

    protected $appends = ['receipt_url'];

    // A hard backstop, not just controller-level validation: no write path
    // (including a future Plaid import or invoice-attach flow) should be
    // able to produce a billable expense with no project to bill it against.
    protected static function booted(): void
    {
        static::saving(function (Expense $expense) {
            if ($expense->is_billable && ! $expense->project_id) {
                throw ValidationException::withMessages([
                    'is_billable' => 'A billable expense must be tied to a project.',
                ]);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    // How a shared cost is divided between clients (hosting), if it is.
    public function splits(): HasMany
    {
        return $this->hasMany(ExpenseSplit::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function getReceiptUrlAttribute(): ?string
    {
        // Private, so through the app; ?v= is a fingerprint of the stored
        // path, so a replaced receipt gets a new (uncached) URL.
        return $this->receipt_path ? route('api.expenses.receipt', [$this, 'v' => substr(md5($this->receipt_path), 0, 8)]) : null;
    }

    // What actually lands on the invoice line item: the expense cost plus
    // its markup. Not the same as billing_status, which just tracks where
    // that amount currently lives.
    public function billableAmount(): float
    {
        return round((float) $this->amount * (1 + (float) $this->markup_percent / 100), 2);
    }

    public function attachToInvoice(Invoice $invoice): void
    {
        if (! $this->is_billable) {
            throw ValidationException::withMessages([
                'is_billable' => 'Only billable expenses can be attached to an invoice.',
            ]);
        }

        // Added as the invoice's last line.
        $item = $invoice->items()->create([
            'description' => $this->name,
            'amount' => $this->billableAmount(),
            'position' => ($invoice->items()->reorder()->max('position') ?? -1) + 1,
        ]);

        $this->update([
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $item->id,
            'billing_status' => $invoice->status === 'paid' ? 'billed_and_paid' : 'billed',
        ]);
    }

    // Reverts to unbilled and removes the invoice line item it created --
    // used both for an explicit detach and for cleanup when the invoice
    // itself is deleted.
    public function detachFromInvoice(): void
    {
        $item = $this->invoiceItem;

        $this->update([
            'invoice_id' => null,
            'invoice_item_id' => null,
            'billing_status' => 'unbilled',
        ]);

        $item?->delete();
    }
}
