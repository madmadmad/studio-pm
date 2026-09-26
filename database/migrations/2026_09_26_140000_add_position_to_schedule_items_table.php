<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Schedule items are ordered by hand (dragged in the list) rather than
    // by date. Existing items keep their date order as their starting one.
    public function up(): void
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('ends_on');
        });

        DB::table('schedule_items')
            ->orderBy('project_id')->orderBy('starts_on')->orderBy('ends_on')->orderBy('id')
            ->get(['id', 'project_id'])
            ->groupBy('project_id')
            ->each(fn ($items) => $items->values()->each(
                fn ($item, $index) => DB::table('schedule_items')->where('id', $item->id)->update(['position' => $index])
            ));
    }

    public function down(): void
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
