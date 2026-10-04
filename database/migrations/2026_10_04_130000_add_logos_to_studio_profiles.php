<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The studio's own logo, uploaded in Settings (public disk): the version
// for light backgrounds, the one for dark (sidebar, sign-in in dark mode),
// and a PNG of the light one for emails and PDFs, which can't show SVG.
// Unset, the bundled Madhouse Studio lockup is used.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('website');
            $table->string('logo_dark_path')->nullable()->after('logo_path');
            $table->string('logo_png_path')->nullable()->after('logo_dark_path');
        });
    }

    public function down(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_dark_path', 'logo_png_path']);
        });
    }
};
