<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Project statuses are now Estimated, Active, Inactive, Completed,
    // Archived -- "On hold" is renamed to "Inactive".
    public function up(): void
    {
        DB::table('projects')->where('status', 'on_hold')->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        DB::table('projects')->where('status', 'inactive')->update(['status' => 'on_hold']);
    }
};
