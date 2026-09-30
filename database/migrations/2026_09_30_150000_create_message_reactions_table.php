<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // An emoji reaction on a message, from one person -- a staff user or a
    // client contact, like a message's sender. Each person reacts with a
    // given emoji once per message (toggling it again removes it).
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            // One of each emoji per person per message. (A null id never
            // collides in a unique index, so each index only binds its own
            // kind of reactor.)
            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->unique(['message_id', 'contact_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
