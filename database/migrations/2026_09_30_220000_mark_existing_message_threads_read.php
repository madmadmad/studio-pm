<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Unread messages start now: every thread already on file counts as read,
// so no one opens the app to a wall of old "unread" threads.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('message_participants')->whereNull('last_read_at')->update(['last_read_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to undo: read times are just reset going forward.
    }
};
