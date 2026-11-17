<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A team member's connected Google account, for their calendars in the
// app: the tokens to act as them (encrypted) and the calendars they chose
// to show.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_email');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at')->nullable();
            // [{id, name, color, can_edit}] -- the calendars they show.
            $table->json('calendars')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_accounts');
    }
};
