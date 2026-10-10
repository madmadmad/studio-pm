<?php

namespace App\Services\LedgerReports;

use App\Models\Account;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Carbon;

// Bookkeeping > Expenses by month (from the ledger): what it costs to run
// the studio, month by month, in a handful of groups -- rent, utilities,
// software, office and so on. Operating expenses only: client media,
// printing, hosting and the rest of cost of revenue are paid for by the
// clients they're billed to, so they're left out. Payroll is a group of
// its own, which the page can leave out too (it dwarfs the rest).
//
// Accounts are grouped by name; one renamed, or added later, lands in
// "Other" until it's listed here.
class ExpensesByMonth
{
    public const PAYROLL = 'payroll';

    // key => [label, account names], in the order they stack.
    public const GROUPS = [
        'rent' => ['Rent & property', ['Rent & Lease Property', 'Real Estate Taxes']],
        'utilities' => ['Utilities & phone', ['Utilities', 'Internet', 'Mobile Phone', 'Telephone']],
        'software' => ['Software & devices', ['Work Devices & Software', 'Studio Software', 'Subscriptions & Memberships']],
        'office' => ['Office', ['Other Office Expenses', 'Electronics & Furniture', 'Equipment Repairs', 'Rental Equipment']],
        'insurance' => ['Insurance', ['Business Insurance', 'Auto Insurance']],
        'professional' => ['Professional services', ['Accounting Fees', 'Professional Services']],
        'marketing' => ['Marketing', ['Advertising & Marketing']],
        'other' => ['Other', []],
        self::PAYROLL => ['Payroll & benefits', [
            'Wages', 'Officer Compensation', 'Payroll Taxes', 'Retirement Expense', 'Health & Life Insurance',
            'HSA fees', 'Payroll Processing Fees', 'Ohio State Workers\' Compensation tax', 'Other Perks & Benefits',
        ]],
    ];

    public static function for(Carbon $from, Carbon $to): array
    {
        // Months still to come are left off, so a year's report ends today.
        $months = [];
        $last = $to->copy()->min(now()->startOfDay());
        for ($m = $from->copy()->startOfMonth(); $m->lte($last); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        $accounts = Balances::accounts()
            ->filter(fn (Account $a) => $a->type === Account::EXPENSE && $a->parent?->system_key !== 'cost_of_revenue')
            ->keyBy('id');

        // [account id][Y-m] => cents, debits less credits.
        $cells = [];
        JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $accounts->keys())
            ->whereDate('journal_entries.entry_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
            ->groupBy('journal_lines.account_id', 'month')
            ->selectRaw('journal_lines.account_id, substr(journal_entries.entry_date, 1, 7) as month, sum(journal_lines.debit_cents) - sum(journal_lines.credit_cents) as cents')
            ->get()
            ->each(function ($row) use (&$cells) {
                $cells[$row->account_id][$row->month] = (int) $row->cents;
            });

        $groupOf = [];
        foreach (self::GROUPS as $key => [, $names]) {
            foreach ($names as $name) {
                $groupOf[$name] = $key;
            }
        }

        $groups = [];
        foreach (self::GROUPS as $key => [$label]) {
            $groups[$key] = ['key' => $key, 'label' => $label, 'months' => array_fill_keys($months, 0), 'total' => 0, 'accounts' => []];
        }
        foreach ($accounts as $account) {
            $row = array_merge(array_fill_keys($months, 0), array_intersect_key($cells[$account->id] ?? [], array_flip($months)));
            $total = array_sum($row);
            if ($total === 0 && ! array_filter($row)) {
                continue;
            }
            $key = $groupOf[$account->name] ?? 'other';
            $groups[$key]['accounts'][] = ['account' => $account->only('id', 'code', 'name'), 'months' => $row, 'total' => $total];
            foreach ($row as $month => $cents) {
                $groups[$key]['months'][$month] += $cents;
            }
            $groups[$key]['total'] += $total;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'months' => $months,
            'groups' => array_values($groups),
        ];
    }

    // Operating expenses, payroll and all, for each month of $year:
    // [month number => cents]. The Bookkeeping chart sets them beside
    // income.
    public static function operatingTotals(int $year): array
    {
        $accountIds = Balances::accounts()
            ->filter(fn (Account $a) => $a->type === Account::EXPENSE && $a->parent?->system_key !== 'cost_of_revenue')
            ->pluck('id');

        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $accountIds)
            ->whereDate('journal_entries.entry_date', '>=', "{$year}-01-01")
            ->whereDate('journal_entries.entry_date', '<=', "{$year}-12-31")
            ->groupBy('month')
            // The month as text ("05"), made a number here: "cast(... as
            // integer)" isn't SQL that MySQL takes.
            ->selectRaw('substr(journal_entries.entry_date, 6, 2) as month, sum(journal_lines.debit_cents) - sum(journal_lines.credit_cents) as cents')
            ->pluck('cents', 'month')
            ->mapWithKeys(fn ($cents, $month) => [(int) $month => (int) $cents])
            ->all();
    }

    // One row per account under its group, a column a month, and totals
    // with and without payroll.
    public static function csvRows(array $report): array
    {
        $rows = [['Group', 'Account', ...$report['months'], 'Total']];
        $totals = ['with' => array_fill_keys($report['months'], 0), 'without' => array_fill_keys($report['months'], 0)];

        foreach ($report['groups'] as $group) {
            foreach ($group['accounts'] as $line) {
                $rows[] = [$group['label'], "{$line['account']['code']} {$line['account']['name']}", ...array_map(fn ($c) => Money::fromCents($c), array_values($line['months'])), Money::fromCents($line['total'])];
            }
            $rows[] = [$group['label'], "Total {$group['label']}", ...array_map(fn ($c) => Money::fromCents($c), array_values($group['months'])), Money::fromCents($group['total'])];
            foreach ($group['months'] as $month => $cents) {
                $totals['with'][$month] += $cents;
                if ($group['key'] !== self::PAYROLL) {
                    $totals['without'][$month] += $cents;
                }
            }
        }

        foreach (['without' => 'Total without payroll', 'with' => 'Total operating expenses'] as $key => $label) {
            $rows[] = ['', $label, ...array_map(fn ($c) => Money::fromCents($c), array_values($totals[$key])), Money::fromCents(array_sum($totals[$key]))];
        }

        return $rows;
    }
}
