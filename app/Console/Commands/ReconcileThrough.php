<?php

namespace App\Console\Commands;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Services\Reconciliation\Reconciler;
use App\Support\Money;
use Illuminate\Console\Command;

// At cutover: marks an account's history reconciled through a date, as one
// finished statement, once the Bonsai import has matched it to the bank's
// and card's statements -- so monthly reconciling starts from there.
// Refused unless the books come to that statement's ending balance.
//
//   php artisan reconcile:through checking 2026-12-31 48210.55
//   php artisan reconcile:through capital_one_card 2026-12-15 3120.40
class ReconcileThrough extends Command
{
    protected $signature = 'reconcile:through {account : checking or capital_one_card} {date : The statement date} {balance : The statement ending balance: in checking, or owed on the card}';

    protected $description = "Mark an account's history reconciled through a statement date (for cutover).";

    public function handle(Reconciler $reconciler): int
    {
        if (! in_array($this->argument('account'), Reconciler::ACCOUNTS, true)) {
            $this->error('The account is one of: '.implode(', ', Reconciler::ACCOUNTS).'.');

            return self::FAILURE;
        }

        try {
            $r = $reconciler->reconcileThrough(Account::forKey($this->argument('account')), $this->argument('date'), Money::toCents($this->argument('balance')));
        } catch (LedgerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Reconciled {$r->label()}: {$r->lines()->count()} lines.");

        return self::SUCCESS;
    }
}
