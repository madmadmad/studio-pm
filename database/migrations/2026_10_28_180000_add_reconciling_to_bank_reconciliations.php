<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Monthly bank and card reconciliation (CPA review, Oct 2026): what a
// statement began with, the rows of its uploaded export (each matched to
// a ledger line, or left over), and who started and finished it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->bigInteger('starting_balance_cents')->default(0)->after('statement_date');
            $table->json('statement_rows')->nullable()->after('statement_ending_balance_cents');
            $table->foreignId('created_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['starting_balance_cents', 'statement_rows']);
        });
    }
};
