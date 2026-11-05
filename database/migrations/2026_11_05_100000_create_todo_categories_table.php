<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A person's own groups on their to-do list (Hot, Proposals, Waiting for
// feedback...): named, colored and ordered by them, private like the
// to-dos. A to-do is in one or none; deleting a group leaves its to-dos
// in none.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('collapsed')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'position']);
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->foreignId('todo_category_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('todo_category_id');
        });
        Schema::dropIfExists('todo_categories');
    }
};
