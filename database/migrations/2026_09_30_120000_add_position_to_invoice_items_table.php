<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Invoice line items can be reordered by hand (dragged in the editor).
    // They're updated in place -- so time and expenses stay billed to them
    // -- which means insertion order can't stand in for display order any
    // more. Existing items keep the order they were created in.
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('amount');
        });

        DB::table('invoice_items')
            ->orderBy('invoice_id')->orderBy('id')
            ->get(['id', 'invoice_id'])
            ->groupBy('invoice_id')
            ->each(fn ($items) => $items->values()->each(
                fn ($item, $index) => DB::table('invoice_items')->where('id', $item->id)->update(['position' => $index])
            ));
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
