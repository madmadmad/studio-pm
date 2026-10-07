<?php

namespace App\Services\LedgerReports;

use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// What the ledger reports share: each account's debits and credits over a
// stretch of dates, and which way its balance is read.
//
// The fiscal year is the calendar year. Nothing posts closing entries, so
// income and expense accounts are read from January 1 of the year in
// question, and earlier years' profit is added up on the fly as retained
// earnings.
class Balances
{
    // [account_id => ['debit' => cents, 'credit' => cents]] for entries
    // dated from $from (or the beginning) through $to.
    public static function sums(?Carbon $from, Carbon $to): array
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $from->toDateString()))
            ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, sum(journal_lines.debit_cents) as debit, sum(journal_lines.credit_cents) as credit')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->account_id => ['debit' => (int) $row->debit, 'credit' => (int) $row->credit]])
            ->all();
    }

    // The accounts that are posted to (not headings), in code order, with
    // their heading's handle for grouping.
    public static function accounts(): Collection
    {
        return Account::whereNotNull('parent_id')->with('parent:id,system_key,name')->orderBy('code')->get();
    }

    // A balance the way it's normally read: debits less credits for assets
    // and expenses, credits less debits for the rest.
    public static function normal(Account $account, ?array $sums): int
    {
        $net = ($sums['debit'] ?? 0) - ($sums['credit'] ?? 0);

        return $account->isDebitNormal() ? $net : -$net;
    }

    public static function isIncomeStatement(Account $account): bool
    {
        return in_array($account->type, [Account::INCOME, Account::EXPENSE], true);
    }

    public static function fiscalYearStart(Carbon $date): Carbon
    {
        return $date->copy()->startOfYear();
    }

    // Profit (income less expenses) over the sums given, in cents.
    public static function profit(Collection $accounts, array $sums): int
    {
        return $accounts->filter(fn (Account $a) => self::isIncomeStatement($a))
            ->sum(fn (Account $a) => ($sums[$a->id]['credit'] ?? 0) - ($sums[$a->id]['debit'] ?? 0));
    }

    // Everything earned before the fiscal year that $asOf falls in.
    public static function priorYearsProfit(Collection $accounts, Carbon $asOf): int
    {
        return self::profit($accounts, self::sums(null, self::fiscalYearStart($asOf)->subDay()));
    }
}
