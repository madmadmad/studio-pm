<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One shared cost divided between clients -- a monthly Linode bill split
// across the clients it hosts -- for hosting profitability (Bookkeeping >
// Hosting profitability). A split is a cost record only: never billed.
// Also adds the Hosting expense category those bills go under.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->unique(['expense_id', 'company_id']);
        });

        if (! DB::table('expense_categories')->where('name', 'Hosting')->exists()) {
            DB::table('expense_categories')->insert(['name' => 'Hosting', 'color' => '#595F64', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_splits');
    }
};
