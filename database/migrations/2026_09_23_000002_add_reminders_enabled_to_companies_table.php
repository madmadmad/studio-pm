<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Null means "use the app-wide default" (config('invoicing.reminders_default_enabled')).
            $table->boolean('reminders_enabled')->nullable()->after('default_payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('reminders_enabled');
        });
    }
};
