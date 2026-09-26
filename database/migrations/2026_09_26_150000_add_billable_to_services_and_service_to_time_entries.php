<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A service says whether time spent on it is billable, and a time entry
    // can name the service it was for -- picking one sets the entry's
    // billable flag from it. Existing services count as billable.
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('billable')->default(true)->after('unit');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('task_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('billable');
        });
    }
};
