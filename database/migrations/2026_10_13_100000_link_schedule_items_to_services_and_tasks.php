<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A schedule item can stand for one of the studio's services (one built
    // from the proposal's services), and a task can sit in a schedule item
    // (its phase), which puts it on that row of the gantt chart.
    public function up(): void
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('schedule_item_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_item_id');
        });

        Schema::table('schedule_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
