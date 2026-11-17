<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The calendar a person's new events go on (Profile → Google Calendar).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_accounts', function (Blueprint $table) {
            $table->string('default_calendar_id')->nullable()->after('calendars');
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_accounts', function (Blueprint $table) {
            $table->dropColumn('default_calendar_id');
        });
    }
};
