<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bank feeds: each connected bank (a Plaid "item") with its encrypted
// access token and where the last sync left off, and the bank charges
// dismissed from the expense list -- so a sync never brings them back.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaid_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_id')->unique();
            $table->text('access_token');
            $table->string('institution_name')->nullable();
            // Each account's display name and last four, keyed by Plaid's account id.
            $table->json('accounts')->nullable();
            $table->text('cursor')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('plaid_dismissals', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_dismissals');
        Schema::dropIfExists('plaid_items');
    }
};
