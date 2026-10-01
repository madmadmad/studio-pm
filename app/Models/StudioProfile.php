<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioProfile extends Model
{
    protected $fillable = [
        'name', 'address', 'email', 'phone', 'website', 'payment_instructions',
        'proposal_disclaimer', 'proposal_email_message', 'invoice_email_message', 'sales_tax_name', 'sales_tax_rate',
    ];

    protected $casts = [
        'sales_tax_rate' => 'decimal:3',
    ];

    // Singleton -- the app only ever has one studio to represent, so there's
    // always exactly one row rather than a set the user picks from. A new
    // one starts from the config defaults for the disclaimer, the proposal
    // and invoice emails, and tax.
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'proposal_disclaimer' => config('proposals.default_disclaimer'),
            'proposal_email_message' => config('proposals.email_template'),
            'invoice_email_message' => config('invoicing.email_template'),
            'sales_tax_name' => config('invoicing.sales_tax.name'),
            'sales_tax_rate' => config('invoicing.sales_tax.rate'),
        ]);
    }

    // The sales tax an invoice takes when "Charge Tax" is switched on, as
    // ['name', 'rate'] -- or null when none is set up (no rate in Settings).
    public function salesTax(): ?array
    {
        return $this->sales_tax_rate !== null
            ? ['name' => $this->sales_tax_name ?: 'Sales tax', 'rate' => (float) $this->sales_tax_rate]
            : null;
    }
}
