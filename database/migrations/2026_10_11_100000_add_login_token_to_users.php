<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The accountant signs in by an emailed link, never a password: the link's
// token (hashed) and when it stops working (App\Services\AccountantSignIn).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login_token')->nullable()->unique()->after('invite_expires_at');
            $table->timestamp('login_token_expires_at')->nullable()->after('login_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['login_token']);
            $table->dropColumn(['login_token', 'login_token_expires_at']);
        });
    }
};
