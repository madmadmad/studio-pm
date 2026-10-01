<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Links shared in a message (a Dropbox folder, a Google Drive file),
// shown as cards beside its file attachments.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_links');
    }
};
