<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    protected $fillable = ['company_id', 'project_id', 'contact_id', 'status', 'surcharge', 'issued_on', 'due_on', 'stripe_checkout_session_id'];

    protected $casts = [
        'surcharge' => 'boolean',
        'issued_on' => 'date',
        'due_on' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            $invoice->public_token ??= Str::random(40);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function subtotal(): float
    {
        return (float) $this->items->sum('amount');
    }

    public function surchargeAmount(): float
    {
        return $this->surcharge ? round($this->subtotal() * 0.03, 2) : 0.0;
    }

    public function total(): float
    {
        return $this->subtotal() + $this->surchargeAmount();
    }

    // Shared by the manual "Mark paid" action and the Stripe webhook, so
    // both paths record the same payment + bookkeeping entry.
    public function recordPayment(): void
    {
        $this->update(['status' => 'paid']);

        $this->payments()->create([
            'amount' => $this->subtotal(),
            'surcharge_amount' => $this->surchargeAmount(),
            'paid_at' => now(),
        ]);

        Transaction::create([
            'type' => 'income',
            'amount' => $this->total(),
            'category' => 'client invoice',
            'occurred_on' => now(),
            'invoice_id' => $this->id,
            'project_id' => $this->project_id,
        ]);
    }
}
