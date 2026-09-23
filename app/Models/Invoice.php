<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\PaymentTerms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    protected $fillable = ['company_id', 'project_id', 'contact_id', 'status', 'surcharge', 'issued_on', 'due_on', 'payment_terms', 'stripe_checkout_session_id', 'sent_at', 'reminders_enabled'];

    protected $casts = [
        'surcharge' => 'boolean',
        'issued_on' => 'date',
        'due_on' => 'date',
        'payment_terms' => PaymentTerms::class,
        'sent_at' => UtcDateTime::class,
        'reminders_enabled' => 'boolean',
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

    // The one place issued_on/due_on get formatted for display (PDF, invoice
    // emails) so a future format change never has to happen in more than
    // one spot. The public/internal web views use their own JS formatDate()
    // helper instead, which is already the single shared spot on that side.
    public function formattedIssuedOn(): string
    {
        return $this->issued_on->format('M j, Y');
    }

    public function formattedDueOn(): string
    {
        return $this->due_on->format('M j, Y');
    }

    // "Net 30" etc. next to the due date, omitted entirely for Custom since
    // there's no fixed term to name -- the date itself already says
    // everything a Custom term needs to.
    public function paymentTermsLabel(): ?string
    {
        return $this->payment_terms === PaymentTerms::Custom ? null : $this->payment_terms->label();
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

    public function invoiceSends(): HasMany
    {
        return $this->hasMany(InvoiceSend::class);
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

    // Invoice-level override wins over the company's, which wins over the
    // app-wide config default -- same precedence as the send modal's
    // "Automatic reminders" dropdown.
    public function effectiveRemindersEnabled(): bool
    {
        return $this->reminders_enabled ?? $this->company->effectiveRemindersEnabled();
    }

    // What the client still owes -- the full total when nothing has been
    // paid yet, otherwise the total minus whatever payments() already
    // recorded. Reminders state this instead of the original total once a
    // partial payment exists.
    public function remainingBalance(): float
    {
        return round($this->total() - (float) $this->payments->sum('amount'), 2);
    }

    // The recipient a send/reminder actually goes to: the invoice's own
    // contact when one was picked, otherwise the company's billing contact,
    // otherwise its primary contact -- the same precedence the rest of the
    // app uses when defaulting a company-level contact.
    public function billingContact(): ?Contact
    {
        return $this->contact
            ?? $this->company->contacts->firstWhere('is_billing', true)
            ?? $this->company->contacts->firstWhere('is_primary', true);
    }

    // Invalidates the old /i/{token} link immediately by swapping in a new
    // one -- used when a link needs to be revoked.
    public function regenerateToken(): void
    {
        // forceFill, not update() -- public_token is deliberately excluded
        // from $fillable (it's only ever set by booted()'s creating hook or
        // here) so it can never be mass-assigned from a request payload.
        $this->forceFill(['public_token' => Str::random(40)])->save();
    }

    // Blocks sending by either method (Send Invoice modal, Step 3): missing
    // contact, no items/zero total, already paid, or missing dates. Contact
    // must be eager-loaded via billingContact()'s relations (company.contacts,
    // contact) and items/payments for this to avoid lazy-loading queries --
    // deliberately not a global $appends entry, only appended explicitly by
    // the invoice Show page.
    public function sendBlockingIssues(): array
    {
        $issues = [];

        if ($this->status === 'paid') {
            $issues[] = 'This invoice is already paid.';
        }

        if ($this->items->isEmpty() || $this->total() <= 0) {
            $issues[] = 'This invoice has no line items or a total of zero.';
        }

        if (! $this->issued_on || ! $this->due_on) {
            $issues[] = 'This invoice is missing an issue date or due date.';
        }

        if (! $this->billingContact()) {
            $issues[] = 'This invoice has no contact assigned.';
        }

        return $issues;
    }

    protected function getSendBlockingIssuesAttribute(): array
    {
        return $this->sendBlockingIssues();
    }

    // Blocks the Email tab specifically (a contact exists but has no email
    // address) -- the Send via URL tab is still usable in that case.
    protected function getContactEmailMissingAttribute(): bool
    {
        $contact = $this->billingContact();

        return (bool) $contact && ! $contact->email;
    }

    // Whether the modal should prompt to bump the issue date to today --
    // only relevant the first time a draft goes out; a target date later
    // than today (a scheduled send) is still covered since issued_on being
    // before today necessarily means it's before that later date too.
    protected function getNeedsIssueDateUpdateAttribute(): bool
    {
        return ! $this->sent_at && $this->issued_on && $this->issued_on->lt(today());
    }

    protected function getRemainingBalanceAttribute(): float
    {
        return $this->remainingBalance();
    }

    protected function getEffectiveRemindersEnabledAttribute(): bool
    {
        return $this->effectiveRemindersEnabled();
    }

    protected function getPublicUrlAttribute(): string
    {
        return url('/i/'.$this->public_token);
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
