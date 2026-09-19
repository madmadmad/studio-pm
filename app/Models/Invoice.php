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
}
