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
            $invoice->invoice_number ??= (static::max('invoice_number') ?? 999) + 1;
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

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function subtotal(): float
    {
        return (float) $this->items->sum('amount');
    }

    // A hypothetical 3% card-processing fee -- used only to build the Stripe
    // Checkout line item for a card payment. It is never added to total():
    // the fee is between the client and Stripe, shown only on Stripe's own
    // page, and never affects what this invoice is worth in the app.
    public function cardSurchargeAmount(): float
    {
        return round($this->subtotal() * 0.03, 2);
    }

    // What the client owes, full stop. Equal to subtotal() -- kept as a
    // separate method since callers throughout the app already read
    // total() rather than subtotal(), not because the two can ever differ.
    public function total(): float
    {
        return $this->subtotal();
    }

    // Whether a card payment (with its 3% fee, shown only at Stripe
    // checkout) is offered for this invoice at all. ACH and check are
    // always available regardless of this flag.
    public function allowsCardPayment(): bool
    {
        return (bool) $this->surcharge;
    }

    // Shared by the manual "Record payment" action and the Stripe webhook,
    // so both paths write the same payment + bookkeeping entry. $method is
    // 'card' | 'ach' | 'check'; $surchargeAmount is whatever Stripe actually
    // collected on top of the invoice (always 0 for ach/check) -- it's
    // recorded on the Payment only, never folded into the invoice or the
    // income Transaction, so bookkeeping can always tell "amount owed" from
    // "amount Stripe actually processed."
    public function recordPayment(string $method, float $baseAmount, float $surchargeAmount = 0.0, ?string $stripePaymentIntentId = null): void
    {
        $this->update(['status' => 'paid']);

        $this->payments()->create([
            'method' => $method,
            'amount' => $baseAmount,
            'surcharge_amount' => $surchargeAmount,
            'stripe_payment_intent_id' => $stripePaymentIntentId,
            'paid_at' => now(),
        ]);

        Transaction::create([
            'type' => 'income',
            'amount' => $baseAmount,
            'category' => 'client invoice',
            'occurred_on' => now(),
            'invoice_id' => $this->id,
            'project_id' => $this->project_id,
        ]);

        $this->expenses()->update(['billing_status' => 'billed_and_paid']);
    }
}
