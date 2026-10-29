<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Notifications\ReconciliationDue;
use App\Services\Reconciliation\Reconciler;
use Illuminate\Console\Command;

// Scheduled early each month (bootstrap/app.php): an alert to whoever keeps
// the books for each account not reconciled in over a month -- our CPA
// wants checking and the card reconciled monthly.
class RemindReconciliation extends Command
{
    protected $signature = 'reconcile:remind';

    protected $description = 'Alert the bookkeepers to accounts not reconciled in over a month.';

    // How old the last finished statement can be.
    private const DAYS = 35;

    public function handle(Reconciler $reconciler): int
    {
        $due = collect(Reconciler::ACCOUNTS)
            ->map(fn (string $key) => Account::forKey($key))
            ->filter(fn (Account $account) => ($reconciler->latestCompleted($account)?->statement_date->diffInDays(now()) ?? PHP_INT_MAX) > self::DAYS)
            ->values();

        if ($due->isNotEmpty()) {
            User::withPermission('bookkeeping')->each->notify(new ReconciliationDue($due->map(fn (Account $a) => [
                'name' => $a->name,
                'since' => $reconciler->latestCompleted($a)?->statement_date->toDateString(),
            ])->all()));
        }
        $this->info($due->isEmpty() ? 'Everything is reconciled.' : 'Due: '.$due->pluck('name')->join(', '));

        return self::SUCCESS;
    }
}
