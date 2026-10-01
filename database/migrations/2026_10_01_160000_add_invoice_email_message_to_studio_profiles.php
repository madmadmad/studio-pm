<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The message the Send Invoice dialog starts with, editable in Settings --
// seeded from the config default it used to be read from.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->text('invoice_email_message')->nullable()->after('proposal_email_message');
        });

        DB::table('studio_profiles')->update([
            'invoice_email_message' => config('invoicing.email_template'),
        ]);
    }

    public function down(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->dropColumn('invoice_email_message');
        });
    }
};
