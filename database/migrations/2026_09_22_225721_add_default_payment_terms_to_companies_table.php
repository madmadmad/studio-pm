<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Null means "use the firm-wide default" (config('invoicing.default_payment_terms')),
            // not "due on receipt" or any other concrete value.
            $table->string('default_payment_terms')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('default_payment_terms');
        });
    }
};
