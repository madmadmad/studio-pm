<?php

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// From the CPA review (Oct 2026): the account codes are final, the
// $200,000 that opened checking is a Shareholder Note Payable, and
// electronics and furniture of $2,500 or more are capitalized to
// Equipment & Furniture (with Accumulated Depreciation for his year-end
// entries). Electronics & Furniture gets a system key first, so posting
// can find it and the seeder doesn't add a second one.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->whereNull('system_key')->where('name', 'Electronics & Furniture')->update([
            'system_key' => 'electronics_furniture',
            'description' => 'Purchases under $2,500. One of $2,500 or more posts to Equipment & Furniture instead (capitalized; CPA, Oct 2026).',
            'updated_at' => now(),
        ]);

        (new ChartOfAccountsSeeder)->run();

        DB::table('accounts')->where('code_is_placeholder', true)->update(['code_is_placeholder' => false, 'updated_at' => now()]);
    }

    // Accounts are never deleted; the rows stay.
    public function down(): void {}
};
