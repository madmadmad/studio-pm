<?php

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Health & Life Insurance gets a system key (health_insurance): a pay run
// entered in Bookkeeping credits the employees' health deduction back to
// it. The account that's already there takes the key, so the seeder
// doesn't add a second one. Payroll Clearing's description now says it's
// for the Bonsai import's pay runs.
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('accounts')->where('system_key', 'health_insurance')->exists()) {
            DB::table('accounts')
                ->where('name', 'Health & Life Insurance')
                ->where('type', 'expense')
                ->whereNull('system_key')
                ->limit(1)
                ->update(['system_key' => 'health_insurance', 'updated_at' => now()]);
        }

        (new ChartOfAccountsSeeder)->run();

        // The seeder only describes accounts it adds; these two already
        // exist, so they get the new wording here (unless it's been edited).
        foreach (ChartOfAccountsSeeder::ACCOUNTS as $rows) {
            foreach ($rows as [, , , $key, $description]) {
                if (in_array($key, ['health_insurance', 'payroll_clearing'], true)) {
                    DB::table('accounts')->where('system_key', $key)
                        ->where(fn ($q) => $q->whereNull('description')->orWhere('description', 'like', 'A pay run\'s wages%'))
                        ->update(['description' => $description, 'updated_at' => now()]);
                }
            }
        }
    }

    // The key stays; nothing else depends on it being removed.
    public function down(): void {}
};
