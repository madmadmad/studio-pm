<?php

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

// Payroll Clearing: a pay run's costs go in from the payroll report, what
// Data Service and American Funds take from checking comes out, and it
// nets to zero. The seeder only adds what's missing.
return new class extends Migration
{
    public function up(): void
    {
        (new ChartOfAccountsSeeder)->run();
    }

    // Accounts are never deleted; the rows stay.
    public function down(): void {}
};
