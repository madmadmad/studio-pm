<?php

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What importing the Bonsai history needs (docs/ledger-plan.md, Phase 6),
// most of it useful afterwards too: a record of what came from where, so
// running the import again skips what's already in; notes on expenses
// ("Check #8662"); a revenue account on an invoice line, for lines whose
// income doesn't follow their service (time on an ad invoice is ad
// management); late fees on a payment; an invoice's old number when it
// can't keep it ("1250-1"); and the Late Fee Income account.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_records', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // 'bonsai'
            $table->string('external_key');
            $table->string('record_type');
            $table->unsignedBigInteger('record_id');
            $table->timestamps();
            $table->unique(['source', 'external_key']);
            $table->index(['record_type', 'record_id']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('late_fee', 10, 2)->default(0)->after('surcharge_amount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('legacy_number')->nullable();
        });

        (new ChartOfAccountsSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('legacy_number'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('late_fee'));
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('revenue_account_id'));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('notes'));
        Schema::dropIfExists('import_records');
    }
};
