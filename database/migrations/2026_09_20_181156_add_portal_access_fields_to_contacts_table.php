<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->rememberToken(); // reuses Laravel's built-in remember-me cookie plumbing, on the 'client' guard
            $table->timestamp('portal_invited_at')->nullable();
            $table->foreignId('portal_invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('remember_token_issued_at')->nullable(); // lets us enforce a real 60-day window despite Laravel's cookie itself being long-lived
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('portal_invited_by');
            $table->dropColumn(['remember_token', 'portal_invited_at', 'remember_token_issued_at', 'last_login_at']);
        });
    }
};
