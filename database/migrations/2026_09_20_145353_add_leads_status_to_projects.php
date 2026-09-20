<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // New lifecycle: Leads -> Estimated (proposal sent) -> Active (proposal
    // accepted) -> Inactive / Completed / Archived (manual). New projects
    // now default to "leads" instead of "active".
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('status')->default('leads')->change();
        });

        // Existing "active" projects that were never actually sold via a
        // sent/accepted proposal are really leads under the new model.
        DB::table('projects')
            ->where('status', 'active')
            ->whereNotIn('id', function ($query) {
                $query->select('project_id')
                    ->from('proposals')
                    ->whereNotNull('project_id')
                    ->whereIn('status', ['sent', 'accepted']);
            })
            ->update(['status' => 'leads']);
    }

    public function down(): void
    {
        DB::table('projects')->where('status', 'leads')->update(['status' => 'active']);

        Schema::table('projects', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
    }
};
