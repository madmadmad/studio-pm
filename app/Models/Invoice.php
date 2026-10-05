<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\PaymentTerms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Invoice extends Model
{
    protected $fillable = ['company_id', 'project_id', 'category_id', 'contact_id', 'status', 'surcharge', 'tax_name', 'tax_rate', 'issued_on', 'due_on', 'payment_terms', 'stripe_checkout_session_id', 'sent_at', 'reminders_enabled',
        'repeat', 'next_repeat_on', 'repeat_subject', 'repeat_message', 'repeat_cc', 'repeated_from_id'];

    public const REPEATS = ['monthly', 'yearly'];

    // Every list and detail shows what an invoice is for.
    protected $with = ['category:id,name'];

    protected $casts = [
        'surcharge' => 'boolean',
        'tax_rate' => 'decimal:3',
        'issued_on' => 'date',
        'due_on' => 'date',
        'payment_terms' => PaymentTerms::class,
        'sent_at' => UtcDateTime::class,
        'reminders_enabled' => 'boolean',
        'next_repeat_on' => 'date',
        'repeat_cc' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            $invoice->public_token ??= Str::random(40);
            $invoice->invoice_number ??= (static::max('invoice_number') ?? 999) + 1;
        });
    }

    // Everything the invoice detail view shows -- the standalone page
    // (InvoicePageController@show) and the project page's invoice drawer
    // (InvoiceController@show) -- so the two can't drift apart.
    public function loadForDetail(): static
    {
        $this->load([
            'items.service', 'items.timeEntries', 'items.expense', 'company.contacts', 'contact', 'project', 'payments',
            'invoiceSends' => fn ($query) => $query->with('sentBy:id,name')->latest('id'),
            // The series a copy belongs to (its drawer says so).
            'repeatedFrom:id,invoice_number,repeat,next_repeat_on',
        ]);

        return $this->append(['send_blocking_issues', 'contact_email_missing', 'needs_issue_date_update', 'remaining_balance', 'effective_reminders_enabled', 'public_url']);
    }

    // The props the detail view takes alongside the invoice itself.
    public static function detailContext(): array
    {
        $studio = StudioProfile::current();

        return [
            'studio' => $studio,
            'invoicingDefaults' => [
                // The message from Settings, else the config default.
                'emailTemplate' => $studio->invoice_email_message ?: config('invoicing.email_template'),
                'emailSubjectTemplate' => config('invoicing.email_subject_template'),
            ],
        ];
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

    // In the order they're arranged in the editor (dragged); id breaks ties
    // for anything created before positions existed.
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoiceSends(): HasMany
    {
        return $this->hasMany(InvoiceSend::class);
    }

    // What it's for beyond project work (Hosting); null is project work.
    // The client-facing title under "Invoice 1014", as a proposal has its
    // own: the project's name, else its category (Hosting...), else the
    // client's name.
    public function documentTitle(): string
    {
        return $this->project?->name ?: ($this->category?->name ?: $this->company->name);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InvoiceCategory::class, 'category_id');
    }

    // The repeating invoice this one is a copy of (CreateRepeatInvoices).
    public function repeatedFrom(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'repeated_from_id');
    }

    // The date after `$from` a repeating invoice is due again: a month or a
    // year on, never spilling into the month after (Jan 31 -> Feb 28).
    public static function nextRepeatDate(string $repeat, Carbon $from): Carbon
    {
        return $repeat === 'yearly' ? $from->copy()->addYearNoOverflow() : $from->copy()->addMonthNoOverflow();
    }

    // Makes the next invoice in a repeating series: a copy of this one --
    // client, contact, category, project, terms, tax, card payment and its
    // lines -- issued on `$issuedOn`, due by its terms, as a draft. The
    // series moves on to the date after.
    public function makeRepeat(Carbon $issuedOn): Invoice
    {
        $this->loadMissing('items');
        // Custom terms have no day count: keep this invoice's own gap.
        $gap = $this->issued_on && $this->due_on ? (int) $this->issued_on->diffInDays($this->due_on) : 30;

        $copy = $this->company->invoices()->create([
            'project_id' => $this->project_id,
            'category_id' => $this->category_id,
            'contact_id' => $this->contact_id,
            'status' => 'draft',
            'surcharge' => $this->surcharge,
            'tax_name' => $this->tax_name,
            'tax_rate' => $this->tax_rate,
            'issued_on' => $issuedOn,
            'due_on' => $this->payment_terms?->dueDateFrom($issuedOn) ?? $issuedOn->copy()->addDays($gap),
            'payment_terms' => $this->payment_terms,
            'reminders_enabled' => $this->reminders_enabled,
            'repeated_from_id' => $this->id,
        ]);

        foreach ($this->items as $item) {
            $copy->items()->create($item->only(['description', 'details', 'amount', 'taxable', 'service_id', 'position']));
        }

        $this->update(['next_repeat_on' => static::nextRepeatDate($this->repeat, $issuedOn)]);

        return $copy;
    }

    // This series' email, for a copy: the subject and message sent the
    // first time, with that invoice's number, amount and due date swapped
    // for the copy's.
    public function repeatEmailFor(Invoice $copy): array
    {
        $swap = fn (?string $text) => $text === null ? null : strtr($text, array_filter([
            '#'.$this->invoice_number => '#'.$copy->invoice_number,
            '$'.number_format($this->total(), 2) => '$'.number_format($copy->total(), 2),
            $this->due_on?->format('M j, Y') => $copy->due_on?->format('M j, Y'),
        ], fn ($to, $from) => $from !== '', ARRAY_FILTER_USE_BOTH));

        return [
            'subject' => $swap($this->repeat_subject) ?? "Invoice #{$copy->invoice_number}",
            'message' => $swap($this->repeat_message) ?? '',
            'cc' => $this->repeat_cc ?? [],
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function subtotal(): float
    {
        return (float) $this->items->sum('amount');
    }

    public function hasTax(): bool
    {
        return $this->tax_rate !== null;
    }

    // The lines tax is charged on, when the invoice charges it.
    public function taxableSubtotal(): float
    {
        return $this->hasTax() ? (float) $this->items->where('taxable', true)->sum('amount') : 0.0;
    }

    // Sales tax on the taxable lines, to the cent (lib/format's invoiceTax).
    public function taxAmount(): float
    {
        return $this->hasTax() ? round($this->taxableSubtotal() * (float) $this->tax_rate / 100, 2) : 0.0;
    }

    // "Ohio sales tax (7.25%)"
    public function taxLabel(): ?string
    {
        return $this->hasTax() ? sprintf('%s (%s%%)', $this->tax_name, rtrim(rtrim(number_format((float) $this->tax_rate, 3), '0'), '.')) : null;
    }

    // A hypothetical 3% card-processing fee -- used only to build the Stripe
    // Checkout line item for a card payment. It is never added to total():
    // the fee is between the client and Stripe, shown only on Stripe's own
    // page, and never affects what this invoice is worth in the app.
    public function cardSurchargeAmount(): float
    {
        return round($this->total() * 0.03, 2);
    }

    // What the client owes: the lines plus any sales tax.
    public function total(): float
    {
        return round($this->subtotal() + $this->taxAmount(), 2);
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

    // Everyone else who should get a copy: the company's other billing
    // contacts with an email address, minus whoever billingContact() is
    // sending to. The Send modal pre-fills its CC field with these, and
    // reminders fall back to them when there's no earlier send to copy.
    public function billingCcEmails(): array
    {
        $to = $this->billingContact()?->email;

        return $this->company->contacts
            ->where('is_billing', true)
            ->pluck('email')
            ->filter(fn ($email) => $email && $email !== $to)
            ->unique()
            ->values()
            ->all();
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

        // The amount received includes any sales tax; the tax is noted
        // apart, since it's owed to the state rather than earned.
        Transaction::create([
            'type' => 'income',
            'amount' => $baseAmount,
            'tax_amount' => min($this->taxAmount(), $baseAmount),
            'taxable_amount' => $this->taxableSubtotal(),
            'category' => 'client invoice',
            'occurred_on' => now(),
            'invoice_id' => $this->id,
            'project_id' => $this->project_id,
        ]);

        $this->expenses()->update(['billing_status' => 'billed_and_paid']);
    }
}
