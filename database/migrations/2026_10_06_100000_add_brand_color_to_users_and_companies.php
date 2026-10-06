<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A person's own color for the app, and a client's for what they see
// (app/Support/BrandPalette). Null is the studio red.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('brand_color', 7)->nullable();
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->string('brand_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('brand_color');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('brand_color');
        });
    }
};
