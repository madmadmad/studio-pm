<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // email, link, reminder
            $table->string('reminder_rule')->nullable(); // e.g. 'due_minus_3', 'due_plus_7' -- only set when type is reminder
            $table->string('status'); // scheduled, queued, sent, failed, cancelled
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('recipients')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            // A plain (non-partial) unique index is enough here: NULL is
            // never considered equal to NULL by a unique index (true on
            // sqlite, mysql, and postgres alike), so email/link rows
            // (reminder_rule always null) are never constrained by it --
            // only reminder rows collide, which is exactly what guarantees
            // each reminder rule fires at most once per invoice even if the
            // daily reminders command runs twice.
            $table->unique(['invoice_id', 'reminder_rule']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sends');
    }
};
