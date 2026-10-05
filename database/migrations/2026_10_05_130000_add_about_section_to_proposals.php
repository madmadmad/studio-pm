<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The About section closing every proposal: its heading and text are the
// studio's (Settings > Proposals; the heading falls back to the studio's
// name), and each proposal can leave it out -- shown by default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->string('proposal_about_heading')->nullable()->after('proposal_disclaimer');
            $table->text('proposal_about')->nullable()->after('proposal_about_heading');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->boolean('show_about')->default(true)->after('team_heading');
        });

        DB::table('studio_profiles')->update(['proposal_about' => config('proposals.default_about')]);
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('show_about');
        });
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->dropColumn(['proposal_about_heading', 'proposal_about']);
        });
    }
};
