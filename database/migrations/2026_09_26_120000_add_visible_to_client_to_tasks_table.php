<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tasks are internal unless the studio turns on "Show client" -- only
    // those appear in the Client Hub. Existing tasks start hidden.
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('visible_to_client')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('visible_to_client');
        });
    }
};
