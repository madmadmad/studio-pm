<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum PaymentTerms: string
{
    case DueOnReceipt = 'due_on_receipt';
    case Net15 = 'net_15';
    case Net30 = 'net_30';
    case Net45 = 'net_45';
    case Net60 = 'net_60';
    case Net90 = 'net_90';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::DueOnReceipt => 'Due on receipt',
            self::Net15 => 'Net 15',
            self::Net30 => 'Net 30',
            self::Net45 => 'Net 45',
            self::Net60 => 'Net 60',
            self::Net90 => 'Net 90',
            self::Custom => 'Custom',
        };
    }

    // Null for Custom -- there's no fixed day count to derive a due date
    // from, the due date is whatever was typed in by hand.
    public function days(): ?int
    {
        return match ($this) {
            self::DueOnReceipt => 0,
            self::Net15 => 15,
            self::Net30 => 30,
            self::Net45 => 45,
            self::Net60 => 60,
            self::Net90 => 90,
            self::Custom => null,
        };
    }

    // The one place due dates get computed from terms -- used by
    // InvoiceController for both new invoices and edits, so the server
    // and the frontend's live preview can never quietly disagree.
    public function dueDateFrom(Carbon $issuedOn): ?Carbon
    {
        $days = $this->days();

        return $days === null ? null : $issuedOn->copy()->addDays($days);
    }

    /**
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $term) => $term !== self::Custom));
    }

    // The terms a client can be set to (Clients > Edit). Narrower than
    // selectable() on purpose: clients get one of the standard net terms,
    // while a single invoice can still be set to anything, Custom included.
    // Mirrored by CLIENT_PAYMENT_TERMS in resources/js/lib/paymentTerms.js.
    /**
     * @return array<int, self>
     */
    public static function forClients(): array
    {
        return [self::Net30, self::Net60, self::Net90];
    }
}
