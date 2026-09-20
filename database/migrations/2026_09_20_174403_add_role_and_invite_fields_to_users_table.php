<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('team_member')->after('email');
            $table->foreignId('invited_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable()->after('invited_by');
            $table->string('invite_token')->nullable()->unique()->after('invited_at');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token');
            $table->timestamp('deactivated_at')->nullable()->after('invite_expires_at');
        });

        // Any user that already existed before roles were introduced was the
        // firm's sole operator -- promote them rather than locking them out.
        DB::table('users')->update(['role' => 'manager']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['role', 'invited_at', 'invite_token', 'invite_expires_at', 'deactivated_at']);
        });
    }
};
