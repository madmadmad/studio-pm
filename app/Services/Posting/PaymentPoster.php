<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

// A client's payment on an invoice -- revenue on cash basis, so this is
// where income is recorded.
//
// Money in: card and ACH land in Stripe Clearing (less Stripe's fee, when
// it's known, which debits Payment Processing Fees), a check goes to
// checking. Credited: the sales tax in the payment to Sales Tax Payable,
// the card surcharge to Card Surcharge Income, and the rest as revenue,
// shared across the invoice's lines by amount. Each line's revenue goes
// to the first of: the rebilled expense's category ("billed as"), the
// line's service, the invoice's category, Design & Development Services.
class PaymentPoster extends Poster
{
    protected function draft(Model $record): ?array
    {
        /** @var Payment $record */
        $invoice = $record->invoice()->with(['items.expense.category.revenueAccount', 'items.service.revenueAccount', 'category.revenueAccount'])->first();
        $paid = Money::toCents($record->amount);
        $surcharge = Money::toCents($record->surcharge_amount);
        if (! $invoice || $paid + $surcharge <= 0) {
            return null;
        }

        // The tax in this payment: the invoice's tax, in proportion when
        // the payment is only part of the total.
        $total = Money::toCents($invoice->total());
        $invoiceTax = Money::toCents($invoice->taxAmount());
        $tax = $total > 0 ? min($paid, (int) round($invoiceTax * $paid / $total)) : 0;
        $fee = $record->viaStripe() ? min(Money::toCents($record->stripe_fee), $paid + $surcharge) : 0;

        $lines = [
            ['account' => $this->account($record->viaStripe() ? 'stripe_clearing' : 'checking'), 'debit_cents' => $paid + $surcharge - $fee],
            ['account' => $this->account('merchant_fees'), 'debit_cents' => $fee],
            ['account' => $this->account('sales_tax_payable'), 'credit_cents' => $tax],
            ['account' => $this->account('surcharge_income'), 'credit_cents' => $surcharge, 'company_id' => $invoice->company_id],
            ...$this->revenue($invoice->items->all(), $invoice->category?->revenueAccount, $paid - $tax, $invoice->company_id),
        ];

        return [
            'date' => $record->paid_at->toDateString(),
            'memo' => "Payment on invoice #{$invoice->invoice_number}",
            'lines' => array_values(array_filter($lines, fn (array $line) => ($line['debit_cents'] ?? 0) > 0 || ($line['credit_cents'] ?? 0) > 0)),
        ];
    }

    // $cents of revenue shared across the lines by amount, one line per
    // revenue account. Each share is rounded and the rounding left over
    // goes on the biggest line, so it adds up to the cent. A discount line
    // (negative) takes its share back off its account.
    private function revenue(array $items, ?Account $categoryAccount, int $cents, ?int $companyId): array
    {
        $amounts = array_map(fn (InvoiceItem $item) => Money::toCents($item->amount), $items);
        $subtotal = array_sum($amounts);
        if ($cents === 0) {
            return [];
        }
        if ($subtotal === 0) {
            return [['account' => $this->mapped($categoryAccount, 'service_revenue'), 'credit_cents' => $cents, 'company_id' => $companyId]];
        }

        $shares = array_map(fn (int $amount) => (int) round($cents * $amount / $subtotal), $amounts);
        $biggest = array_keys($amounts, max($amounts))[0];
        $shares[$biggest] += $cents - array_sum($shares);

        $byAccount = [];
        foreach ($items as $i => $item) {
            $account = $this->revenueAccount($item, $categoryAccount);
            $byAccount[$account->id] ??= ['account' => $account, 'cents' => 0];
            $byAccount[$account->id]['cents'] += $shares[$i];
        }

        return array_map(fn (array $row) => $row['cents'] >= 0
            ? ['account' => $row['account'], 'credit_cents' => $row['cents'], 'company_id' => $companyId]
            : ['account' => $row['account'], 'debit_cents' => -$row['cents'], 'company_id' => $companyId], array_values($byAccount));
    }

    private function revenueAccount(InvoiceItem $item, ?Account $categoryAccount): Account
    {
        $mapped = $item->expense?->category?->revenueAccount
            ?? $item->service?->revenueAccount
            ?? $categoryAccount;

        return $this->mapped($mapped, 'service_revenue');
    }
}
