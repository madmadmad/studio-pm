<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A reconciliation started from the statement's PDF keeps it (on the
// private disk, for the accountant), what the bank said it began with,
// and the bank's balance at the end of each day -- to show where the
// books and the bank part ways.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->string('statement_file_path')->nullable()->after('statement_rows');
            $table->string('statement_file_name')->nullable()->after('statement_file_path');
            $table->bigInteger('statement_beginning_cents')->nullable()->after('statement_file_name');
            $table->json('statement_balances')->nullable()->after('statement_beginning_cents');
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->dropColumn(['statement_file_path', 'statement_file_name', 'statement_beginning_cents', 'statement_balances']);
        });
    }
};
