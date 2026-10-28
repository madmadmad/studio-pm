<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A pay run entered from the payroll provider's Cash Requirement Summary:
// the report itself (private), what was read from it, and the journal entry
// it made. The check date is how a report uploaded twice is caught.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('check_date')->index();
            $table->date('period_date')->nullable();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->string('report_path');
            $table->string('report_filename');
            $table->json('summary');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
