<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The sales tax inside an income entry's amount -- collected for the
// state, so bookkeeping reports it apart from income -- and the taxable
// sales it was charged on, for the monthly sales tax report. `amount`
// stays the full sum received.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('tax_amount', 10, 2)->default(0)->after('amount');
            $table->decimal('taxable_amount', 10, 2)->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['tax_amount', 'taxable_amount']);
        });
    }
};
