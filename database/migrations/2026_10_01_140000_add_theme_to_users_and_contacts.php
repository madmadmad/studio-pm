<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Each person's appearance: dark (the default, when unset), light, or
// system (follow the computer's setting). Staff and client contacts alike,
// set on their profile; the page is drawn in it from the first frame
// (resources/views/app.blade.php).
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'contacts'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('theme', 10)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'contacts'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('theme');
            });
        }
    }
};
