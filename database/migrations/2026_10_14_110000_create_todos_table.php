<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Each person's own running to-do list (My Work → To-do): short lines,
// not tied to a project, never shared. `done_at` is when it was ticked;
// `position` its place among the open ones.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 500);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'done_at', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
