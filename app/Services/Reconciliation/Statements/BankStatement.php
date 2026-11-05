<?php

namespace App\Services\Reconciliation\Statements;

use App\Exceptions\LedgerException;
use Smalot\PdfParser\Parser;

// A bank or card statement read from its PDF, for reconciling
// (Reconciler::start): which account it's for, its dates and balances,
// every transaction on it, and the bank's balance at the end of each day.
//
// Rows are as StatementMatch has them -- the change to the account as the
// ledger sees it (debit positive): a deposit into checking or a payment
// on the card positive, a check or a card charge negative. Balances are
// as the statement shows them: money in checking, what's owed on the card.
//
// A reader checks what it read against the statement's own counts and
// totals, and refuses one that doesn't add up -- misread, or a layout it
// doesn't know -- rather than reconcile against half a statement.
final class BankStatement
{
    /**
     * @param  list<array{date: string, cents: int, description: string}>  $rows
     * @param  array<string, int>  $dailyBalances  date => cents, as the statement shows them
     * @param  array{in_count: int, in_cents: int, out_count: int, out_cents: int}  $totals  the statement's own, money in and out
     */
    public function __construct(
        public readonly string $bank,
        public readonly string $accountKey,
        public readonly string $accountLast4,
        public readonly string $periodStart,
        public readonly string $statementDate,
        public readonly int $beginningCents,
        public readonly int $endingCents,
        public readonly array $rows,
        public readonly array $dailyBalances,
        public readonly array $totals,
    ) {}

    public static function fromPdf(string $path): self
    {
        try {
            $text = (new Parser)->parseFile($path)->getText();
        } catch (\Throwable) {
            throw new LedgerException('That file couldn’t be read as a PDF.');
        }

        return self::fromText($text);
    }

    // The bank's reader, by who the statement is from.
    public static function fromText(string $text): self
    {
        if (str_contains($text, 'Waterford Bank') || str_contains($text, 'waterfordbankna.com')) {
            return WaterfordStatement::read($text);
        }
        if (stripos($text, 'capitalone.com') !== false && str_contains($text, 'Billing Cycle')) {
            return CapitalOneStatement::read($text);
        }

        throw new LedgerException('That PDF isn’t a statement this can read (Waterford checking or the Capital One card).');
    }

    // "$1,234.56" as cents.
    public static function cents(string $amount): int
    {
        return (int) round((float) str_replace(['$', ',', ' '], '', $amount) * 100);
    }

    // What the summary before starting shows: the statement's figures and
    // the count of rows read.
    public function toArray(): array
    {
        return [
            'bank' => $this->bank,
            'account_key' => $this->accountKey,
            'account_last4' => $this->accountLast4,
            'period_start' => $this->periodStart,
            'statement_date' => $this->statementDate,
            'beginning_cents' => $this->beginningCents,
            'ending_cents' => $this->endingCents,
            'totals' => $this->totals,
            'rows' => count($this->rows),
        ];
    }
}
