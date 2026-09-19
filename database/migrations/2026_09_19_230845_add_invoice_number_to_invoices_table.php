<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('invoice_number')->nullable()->unique()->after('id');
        });

        // Backfill existing invoices in creation order, starting at 1000 --
        // raw query, not the Eloquent model, since invoice_number is meant
        // to only ever be set by Invoice::booted(), never mass-assigned.
        $number = 1000;
        DB::table('invoices')->orderBy('id')->pluck('id')->each(function ($id) use (&$number) {
            DB::table('invoices')->where('id', $id)->update(['invoice_number' => $number++]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_number');
        });
    }
};
