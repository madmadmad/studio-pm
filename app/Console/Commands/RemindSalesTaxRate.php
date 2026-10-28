<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SalesTaxRateCheck;
use Illuminate\Console\Command;

// Scheduled for early January (bootstrap/app.php): reminds the super
// admins to check the invoice sales tax rate for the new year.
class RemindSalesTaxRate extends Command
{
    protected $signature = 'sales-tax:remind';

    protected $description = 'Remind the super admins to check the sales tax rate for the new year.';

    public function handle(): int
    {
        $admins = User::where('role', User::ROLE_SUPER_ADMIN)->get();
        $admins->each->notify(new SalesTaxRateCheck);
        $this->info("Reminded {$admins->count()} super admin(s).");

        return self::SUCCESS;
    }
}
