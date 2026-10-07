<?php

namespace App\Services\LedgerReports;

use App\Models\Account;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Carbon;

// Bookkeeping > General ledger: every line posted to each account between
// two dates, with the balance before, a running balance, and the balance
// after -- read the way the account normally runs (cash up, a card owed
// up). Income and expense accounts start the year at zero.
class GeneralLedger
{
    public static function for(Carbon $from, Carbon $to, ?int $accountId = null): array
    {
        $accounts = Balances::accounts()->when($accountId, fn ($all) => $all->where('id', $accountId));
        $before = $from->copy()->subDay();
        $openingAll = Balances::sums(null, $before);
        $openingThisYear = Balances::sums(Balances::fiscalYearStart($from), $before);

        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereDate('journal_entries.entry_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
            ->when($accountId, fn ($q) => $q->where('journal_lines.account_id', $accountId))
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.entry_number')->orderBy('journal_lines.id')
            ->select('journal_lines.*', 'journal_entries.entry_date', 'journal_entries.entry_number', 'journal_entries.memo')
            ->with('company:id,name')
            ->get()
            ->groupBy('account_id');

        $rows = [];
        foreach ($accounts as $account) {
            /** @var Account $account */
            $opening = Balances::normal($account, (Balances::isIncomeStatement($account) ? $openingThisYear : $openingAll)[$account->id] ?? null);
            $accountLines = $lines->get($account->id, collect());
            if ($accountLines->isEmpty() && $opening === 0) {
                continue;
            }

            $balance = $opening;
            $sign = $account->isDebitNormal() ? 1 : -1;
            $rows[] = [
                'account' => $account->only('id', 'code', 'name', 'type'),
                'opening' => $opening,
                'debits' => (int) $accountLines->sum('debit_cents'),
                'credits' => (int) $accountLines->sum('credit_cents'),
                'lines' => $accountLines->map(function (JournalLine $line) use (&$balance, $sign) {
                    $balance += $sign * ($line->debit_cents - $line->credit_cents);

                    return [
                        'id' => $line->id,
                        'date' => Carbon::parse($line->entry_date)->toDateString(),
                        'entry_number' => $line->entry_number,
                        'memo' => $line->memo,
                        'description' => $line->description,
                        'client' => $line->company?->name,
                        'debit' => $line->debit_cents,
                        'credit' => $line->credit_cents,
                        'balance' => $balance,
                    ];
                })->values()->all(),
                'closing' => $balance,
            ];
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'account_id' => $accountId, 'accounts' => $rows];
    }

    public static function csvRows(array $report): array
    {
        $rows = [['Account', 'Date', 'Entry', 'Memo', 'Client', 'Debit', 'Credit', 'Balance']];
        foreach ($report['accounts'] as $account) {
            $name = "{$account['account']['code']} {$account['account']['name']}";
            $rows[] = [$name, $report['from'], '', 'Opening balance', '', '', '', Money::fromCents($account['opening'])];
            foreach ($account['lines'] as $line) {
                $rows[] = [
                    $name, $line['date'], $line['entry_number'], trim($line['memo'].($line['description'] ? " – {$line['description']}" : '')), $line['client'] ?? '',
                    $line['debit'] ? Money::fromCents($line['debit']) : '', $line['credit'] ? Money::fromCents($line['credit']) : '', Money::fromCents($line['balance']),
                ];
            }
            $rows[] = [$name, $report['to'], '', 'Closing balance', '', Money::fromCents($account['debits']), Money::fromCents($account['credits']), Money::fromCents($account['closing'])];
        }

        return $rows;
    }
}
