<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_billable')->default(false);
            $table->decimal('markup_percent', 5, 2)->default(0);
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_interval')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_filename')->nullable();
            $table->string('source_label')->nullable();
            $table->string('plaid_transaction_id')->nullable()->unique();
            $table->string('billing_status')->default('unbilled');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
