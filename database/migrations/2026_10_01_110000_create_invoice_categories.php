<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// What an invoice is for, beyond project work: Hosting to start with,
// editable in Settings. An invoice with no category is project work (every
// invoice so far). Reported by year in Bookkeeping > Invoices by category.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('project_id')->constrained('invoice_categories')->nullOnDelete();
        });

        DB::table('invoice_categories')->insert(['name' => 'Hosting', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('invoice_categories');
    }
};
