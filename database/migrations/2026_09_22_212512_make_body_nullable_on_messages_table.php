<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // An attachments-only message has no body text at all.
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->longText('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->longText('body')->nullable(false)->change();
        });
    }
};
