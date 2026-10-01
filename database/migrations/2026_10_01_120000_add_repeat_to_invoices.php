<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Repeating invoices (hosting, monthly or yearly), set from the send
// dialog. The invoice first sent holds the series: how often, when the
// next copy is due, and the email to send it with. Each copy points back
// at it (repeated_from_id). See App\Console\Commands\CreateRepeatInvoices.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('repeat')->nullable()->after('reminders_enabled'); // monthly | yearly
            $table->date('next_repeat_on')->nullable()->after('repeat');
            $table->string('repeat_subject')->nullable()->after('next_repeat_on');
            $table->text('repeat_message')->nullable()->after('repeat_subject');
            $table->json('repeat_cc')->nullable()->after('repeat_message');
            $table->foreignId('repeated_from_id')->nullable()->after('repeat_cc')->constrained('invoices')->nullOnDelete();
            $table->index(['repeat', 'next_repeat_on']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['repeat', 'next_repeat_on']);
            $table->dropConstrainedForeignId('repeated_from_id');
            $table->dropColumn(['repeat', 'next_repeat_on', 'repeat_subject', 'repeat_message', 'repeat_cc']);
        });
    }
};
