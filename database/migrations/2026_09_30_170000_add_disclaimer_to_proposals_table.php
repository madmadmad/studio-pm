<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A proposal's disclaimer: the scope note shown between its scope of
    // work and its services, starting from the default and editable on
    // each proposal. Plain text.
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->text('disclaimer')->nullable()->after('body');
        });

        // Drafts and sent proposals get the default. Accepted ones are left
        // without one -- the client accepted them as they were.
        DB::table('proposals')
            ->whereIn('status', ['draft', 'sent'])
            ->update(['disclaimer' => config('proposals.default_disclaimer')]);
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('disclaimer');
        });
    }
};
