<?php

namespace App\Models;

use App\Enums\PaymentTerms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = ['name', 'phone', 'address_line1', 'city', 'state', 'postal_code', 'status', 'default_payment_terms', 'reminders_enabled'];

    protected $casts = [
        'default_payment_terms' => PaymentTerms::class,
        'reminders_enabled' => 'boolean',
    ];

    // The frontend's new-invoice form needs an always-concrete value to
    // apply when a client is picked (the raw column is nullable and means
    // "use the firm default," which JS has no way to resolve on its own).
    protected $appends = ['effective_payment_terms', 'effective_reminders_enabled'];

    // Null default_payment_terms means "use the firm-wide default" -- this
    // is the one place that resolves to an actual, always-concrete term.
    public function effectivePaymentTerms(): PaymentTerms
    {
        return $this->default_payment_terms ?? PaymentTerms::from(config('invoicing.default_payment_terms'));
    }

    protected function getEffectivePaymentTermsAttribute(): string
    {
        return $this->effectivePaymentTerms()->value;
    }

    // Null reminders_enabled means "use the app-wide default" -- mirrors
    // effectivePaymentTerms() exactly.
    public function effectiveRemindersEnabled(): bool
    {
        return $this->reminders_enabled ?? (bool) config('invoicing.reminders_default_enabled');
    }

    protected function getEffectiveRemindersEnabledAttribute(): bool
    {
        return $this->effectiveRemindersEnabled();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
