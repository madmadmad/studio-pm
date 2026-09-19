<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_token')->nullable()->unique()->after('id');
        });

        // Raw query, not the Eloquent model -- public_token isn't in
        // Invoice::$fillable (it's only ever set directly, never via mass
        // assignment), so ->update() here would silently no-op.
        DB::table('invoices')->whereNull('public_token')->get(['id'])->each(function ($row) {
            DB::table('invoices')->where('id', $row->id)->update(['public_token' => Str::random(40)]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
