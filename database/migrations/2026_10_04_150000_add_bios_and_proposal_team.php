<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bios for proposals: each staff member's position and a rich-text bio
// (their photo is their avatar), and on a proposal the team section --
// which people, in order, under what heading.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title')->nullable()->after('name');
            $table->text('bio')->nullable()->after('job_title');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->json('team_user_ids')->nullable()->after('disclaimer');
            $table->string('team_heading')->nullable()->after('team_user_ids');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['team_user_ids', 'team_heading']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['job_title', 'bio']);
        });
    }
};
