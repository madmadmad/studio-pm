<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which notifications a person's devices get (Profile → Notifications),
// once they've turned them on for a device: chat (direct messages and
// mentions), an accepted proposal, a paid invoice. All on to start.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('push_chat')->default(true);
            $table->boolean('push_proposals')->default(true);
            $table->boolean('push_invoices')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['push_chat', 'push_proposals', 'push_invoices']);
        });
    }
};
