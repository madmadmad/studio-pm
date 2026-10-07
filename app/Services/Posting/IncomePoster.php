<?php

namespace App\Services\Posting;

use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

// Income entered by hand (Bookkeeping's "Add income"): debit the account
// it went into (checking unless it says otherwise), credit Other Income,
// and the sales tax in it to Sales Tax Payable.
//
// An invoice payment's income row doesn't post -- the Payment does, so
// the income isn't counted twice.
class IncomePoster extends Poster
{
    protected function draft(Model $record): ?array
    {
        /** @var Transaction $record */
        if ($record->type !== 'income' || $record->invoice_id || $record->category === Transaction::CLIENT_INVOICE) {
            return null;
        }

        $cents = Money::toCents($record->amount);
        $tax = min($cents, Money::toCents($record->tax_amount));
        if ($cents <= 0) {
            return null;
        }

        $lines = array_filter([
            ['account' => $this->mapped($record->depositAccount, 'checking'), 'debit_cents' => $cents],
            ['account' => $this->account('sales_tax_payable'), 'credit_cents' => $tax],
            ['account' => $this->account('other_income'), 'credit_cents' => $cents - $tax],
        ], fn (array $line) => ($line['debit_cents'] ?? 0) > 0 || ($line['credit_cents'] ?? 0) > 0);

        return ['date' => $record->occurred_on, 'memo' => $record->description ?: 'Other income', 'lines' => array_values($lines)];
    }
}
