<?php

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The starting chart of accounts, and what the app's records post to:
// each expense category an expense account (plus a cost-of-revenue one
// when the expense is billed to a client -- Advertising), and each
// service and invoice category a revenue account. Also brings the expense
// categories in line with our Bonsai tags (ChartOfAccountsSeeder), folding
// the app's original categories into their Bonsai equivalents.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Until our CPA supplies real account numbers.
            $table->boolean('code_is_placeholder')->default(false)->after('code');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billable_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        Schema::table('invoice_categories', function (Blueprint $table) {
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        $seeder = new ChartOfAccountsSeeder;
        $seeder->foldRenamedCategories();
        $seeder->run();

        DB::table('invoice_categories')->where('name', 'Hosting')->update([
            'revenue_account_id' => DB::table('accounts')->where('system_key', 'hosting_revenue')->value('id'),
        ]);
    }

    // Takes the columns off and leaves the accounts and categories as they
    // are -- categories in use can't be un-merged.
    public function down(): void
    {
        Schema::table('invoice_categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('revenue_account_id'));
        Schema::table('services', fn (Blueprint $table) => $table->dropConstrainedForeignId('revenue_account_id'));
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billable_account_id');
            $table->dropConstrainedForeignId('account_id');
        });
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('code_is_placeholder'));
    }
};
