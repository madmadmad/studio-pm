<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing rows predate the participant model entirely (no
        // message_participants row could ever exist for them) and were
        // explicitly cleared to delete rather than carried forward.
        DB::table('messages')->delete();

        Schema::table('messages', function (Blueprint $table) {
            // These only made sense for the old single-recipient email-log
            // model (one fixed "to" address, staff vs. client "direction").
            // A thread now has an explicit participant list instead.
            $table->dropColumn(['direction', 'to_email', 'from_email']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('direction')->default('outbound');
            $table->string('to_email')->nullable();
            $table->string('from_email')->nullable();
        });
    }
};
