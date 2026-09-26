<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A project's schedule: phases or milestones, each a date range, shown
    // as a list and a gantt chart on the project's Schedule tab.
    public function up(): void
    {
        Schema::create('schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->index(['project_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_items');
    }
};
