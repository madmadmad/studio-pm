<?php

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

// Printing we buy for clients and resell, marked up: Printing Cost (cost
// of revenue), Printing (income) and a Printing expense category. The
// seeder only adds what's missing, so this brings an existing chart up to
// date without touching anything already there.
return new class extends Migration
{
    public function up(): void
    {
        (new ChartOfAccountsSeeder)->run();
    }

    // Accounts are never deleted; the rows stay.
    public function down(): void {}
};
