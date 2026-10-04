<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StudioProfile extends Model
{
    protected $fillable = [
        'name', 'address', 'email', 'phone', 'website', 'payment_instructions',
        'proposal_disclaimer', 'proposal_email_message', 'invoice_email_message', 'email_templates', 'sales_tax_name', 'sales_tax_rate',
    ];

    protected $casts = [
        'sales_tax_rate' => 'decimal:3',
        'email_templates' => 'array',
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

    // The bundled lockup, used until one's uploaded in Settings.
    public const DEFAULT_LOGO = '/images/studio-lockup.svg';

    public const DEFAULT_LOGO_DARK = '/images/studio-lockup-rev.svg';

    public const DEFAULT_LOGO_PNG = '/images/studio-lockup.png';

    // The logo for light backgrounds, as a URL path.
    public function logoUrl(): string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : self::DEFAULT_LOGO;
    }

    // For dark backgrounds: the uploaded reversed one; else, with a custom
    // logo, that same logo (better than the bundled lockup); else the
    // bundled reversed lockup.
    public function logoDarkUrl(): string
    {
        return match (true) {
            (bool) $this->logo_dark_path => Storage::disk('public')->url($this->logo_dark_path),
            (bool) $this->logo_path => $this->logoUrl(),
            default => self::DEFAULT_LOGO_DARK,
        };
    }

    // The PNG for emails (an absolute URL) ...
    public function logoPngUrl(): string
    {
        return $this->logo_png_path ? url(Storage::disk('public')->url($this->logo_png_path)) : url(self::DEFAULT_LOGO_PNG);
    }

    // ... and for PDFs (a file on disk).
    public function logoPngFile(): string
    {
        return $this->logo_png_path ? Storage::disk('public')->path($this->logo_png_path) : public_path(ltrim(self::DEFAULT_LOGO_PNG, '/'));
    }

    // The studio's name for correspondence (email subjects, the mail
    // template), from Settings.
    public static function brandName(): string
    {
        return static::current()->name ?: 'Madhouse Studio';
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
