<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_participants', function (Blueprint $table) {
            $table->id();
            // Always points at a root/thread message, never a reply --
            // participation is a thread-level concept.
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->index(['message_id', 'user_id']);
            $table->index(['message_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_participants');
    }
};
