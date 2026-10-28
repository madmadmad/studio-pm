<?php

namespace App\Services\Posting;

use App\Models\Refund;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

// A refund to a client: a payment run backwards. Our CPA wants it taken
// back off the revenue it was booked to (Oct 2026), so it's shared across
// the invoice's lines by amount exactly as a payment is, and its share of
// the sales tax comes back off Sales Tax Payable; any card fee given back
// comes off Card Surcharge Income. The money leaves Stripe Clearing for a
// card refund (Stripe keeps its own processing fee), checking otherwise.
class RefundPoster extends PaymentPoster
{
    protected function draft(Model $record): ?array
    {
        /** @var Refund $record */
        $invoice = $record->invoice()->with(self::INVOICE_WITH)->first();
        $refunded = Money::toCents($record->amount);
        $fee = Money::toCents($record->surcharge_amount);
        if (! $invoice || $refunded + $fee <= 0) {
            return null;
        }

        $total = Money::toCents($invoice->total());
        $tax = $total > 0 ? min($refunded, (int) round(Money::toCents($invoice->taxAmount()) * $refunded / $total)) : 0;

        // The payment's revenue lines, turned around.
        $revenue = array_map(fn (array $line) => [
            'debit_cents' => $line['credit_cents'] ?? 0,
            'credit_cents' => $line['debit_cents'] ?? 0,
        ] + $line, $this->revenue($invoice->items->all(), $invoice->category?->revenueAccount, $refunded - $tax, $invoice->company_id));

        $lines = [
            ['account' => $this->account($record->viaStripe() ? 'stripe_clearing' : 'checking'), 'credit_cents' => $refunded + $fee],
            ['account' => $this->account('sales_tax_payable'), 'debit_cents' => $tax],
            ['account' => $this->account('surcharge_income'), 'debit_cents' => $fee, 'company_id' => $invoice->company_id],
            ...$revenue,
        ];

        return [
            'date' => $record->refunded_at->toDateString(),
            'memo' => "Refund on invoice #{$invoice->invoice_number}",
            'lines' => array_values(array_filter($lines, fn (array $line) => ($line['debit_cents'] ?? 0) > 0 || ($line['credit_cents'] ?? 0) > 0)),
        ];
    }
}
