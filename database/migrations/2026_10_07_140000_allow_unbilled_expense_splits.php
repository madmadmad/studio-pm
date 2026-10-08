<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A split row with no client: the part of a shared hosting bill that's
// for servers nobody is billed for (internal sites, ones hosted free).
// Hosting profitability shows it as Not billed rather than Unassigned.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_splits', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expense_splits', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });
    }
};
