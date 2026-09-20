<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // 'card', 'ach', or 'check' -- lets recordPayment() know whether a
            // surcharge is legitimate instead of inferring it from the
            // invoice's own (now unrelated) surcharge flag.
            $table->string('method')->nullable()->after('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
