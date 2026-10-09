<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A GIF from GIPHY on a Chat message: its id, title, size and GIPHY's own
// URL for it, as GIPHY gave them when it was sent (App\Services\Giphy).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->json('gif')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('gif');
        });
    }
};
