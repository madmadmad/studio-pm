<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // First-send timestamp only -- resends and reminders never touch
            // this, it's purely "when did this invoice first go out."
            $table->timestamp('sent_at')->nullable()->after('status');

            // Null means "use the client's/app's default" -- same
            // nullable-override shape as Company::default_payment_terms.
            $table->boolean('reminders_enabled')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'reminders_enabled']);
        });
    }
};
