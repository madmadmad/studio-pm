<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Paying online by card is always offered now (no per-invoice switch):
// new invoices default to it, and every unpaid one gets it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('surcharge')->default(true)->change();
        });

        DB::table('invoices')->where('status', '!=', 'paid')->update(['surcharge' => true]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('surcharge')->default(false)->change();
        });
    }
};
