<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The tasks each person has starred -- like project_favorites, their own
// short list of what they're on, shown on their Profile's Tasks tab.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_favorites');
    }
};
