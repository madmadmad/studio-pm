<?php

namespace App\Console\Commands;

use App\Models\PlaidItem;
use App\Services\PlaidClient;
use Illuminate\Console\Command;

// Sandbox testing: a new charge on the connected test bank, for checking
// that the next sync imports it (and only it). The bank must have been
// connected with the `user_transactions_dynamic` test login.
class CreatePlaidSandboxTransaction extends Command
{
    protected $signature = 'plaid:sandbox-charge {amount=42.50} {description=Sandbox test charge}';

    protected $description = 'Sandbox only: add a charge to the connected test bank.';

    public function handle(PlaidClient $plaid): int
    {
        if (config('services.plaid.env') !== 'sandbox') {
            $this->error('Only in the Plaid sandbox (PLAID_ENV=sandbox).');

            return self::FAILURE;
        }

        $item = PlaidItem::latest('id')->firstOrFail();
        $plaid->createSandboxTransaction($item->access_token, (float) $this->argument('amount'), $this->argument('description'));
        $this->info("Added \${$this->argument('amount')} \"{$this->argument('description')}\" to {$item->institution_name}. Sync to import it.");

        return self::SUCCESS;
    }
}
