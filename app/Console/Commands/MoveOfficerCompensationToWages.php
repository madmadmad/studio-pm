<?php

namespace App\Console\Commands;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\JournalLine;
use App\Services\Ledger;
use Illuminate\Console\Command;

// One-off (safe to rerun): our CPA wants all gross pay in Wages -- he takes
// the officer's share from the W-2 at tax time -- so whatever pay runs put
// in Officer Compensation is moved to Wages. Posted entries can't change,
// so each pay date gets a reclassifying entry of its own, keeping every
// month's figures right. A date already moved nets to zero and is skipped,
// so running it again adds nothing. Dates in a locked period are reported
// and left alone.
class MoveOfficerCompensationToWages extends Command
{
    protected $signature = 'ledger:officer-comp-to-wages {--force : Post the entries (without it, only shows what would be moved)}';

    protected $description = 'Move everything in Officer Compensation to Wages, one entry per pay date.';

    public function handle(Ledger $ledger): int
    {
        $officer = Account::forKey('officer_compensation');
        $wages = Account::forKey('wages');

        $byDate = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $officer->id)
            ->selectRaw('journal_entries.entry_date as day, sum(journal_lines.debit_cents) - sum(journal_lines.credit_cents) as net')
            ->groupBy('journal_entries.entry_date')
            ->orderBy('journal_entries.entry_date')
            ->get()
            ->filter(fn ($row) => (int) $row->net !== 0);

        if ($byDate->isEmpty()) {
            $this->info('Nothing in Officer Compensation to move.');

            return self::SUCCESS;
        }

        $moved = 0;
        foreach ($byDate as $row) {
            $day = substr((string) $row->day, 0, 10);
            $net = (int) $row->net;
            $amount = '$'.number_format(abs($net) / 100, 2);

            if (! $this->option('force')) {
                $this->line("{$day}: would move {$amount}");

                continue;
            }

            try {
                $entry = $ledger->post($day, [
                    ['account' => $net > 0 ? $wages : $officer, 'debit_cents' => abs($net)],
                    ['account' => $net > 0 ? $officer : $wages, 'credit_cents' => abs($net)],
                ], 'Officer compensation moved to Wages (CPA: all gross pay in Wages)');
                $this->line("{$day}: moved {$amount} (entry #{$entry->entry_number})");
                $moved++;
            } catch (LedgerException $e) {
                $this->warn("{$day}: left alone, {$e->getMessage()}");
            }
        }

        $this->info($this->option('force')
            ? "Moved {$moved} of {$byDate->count()} pay dates."
            : "{$byDate->count()} pay dates to move. Run again with --force to post the entries.");

        return self::SUCCESS;
    }
}
