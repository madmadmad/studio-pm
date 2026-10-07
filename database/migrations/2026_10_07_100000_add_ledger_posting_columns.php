<?php

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What the app's records need so they can post to the ledger themselves
// (docs/ledger-plan.md, Phase 3): which account paid an expense or took in
// income, Stripe's fee on a payment, and how a category's expenses read
// when rebilled to a client -- the revenue account (Hosting → Hosting) and
// whether the line is taxable (Printing).
return new class extends Migration
{
    public function up(): void
    {
        // Null: the Capital One card, where nearly everything is charged.
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('paid_from_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        // Null: checking.
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('deposit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        // Stripe's processing fee on the charge, read from its balance
        // transaction when the payment arrives. Null when unknown (check,
        // or the lookup failed): the payout entry picks the fee up then.
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('stripe_fee', 10, 2)->nullable()->after('surcharge_amount');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('taxable_when_billed')->default(false);
        });

        (new ChartOfAccountsSeeder)->seedRebilling();
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revenue_account_id');
            $table->dropColumn('taxable_when_billed');
        });
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('stripe_fee'));
        Schema::table('transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('deposit_account_id'));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('paid_from_account_id'));
    }
};
