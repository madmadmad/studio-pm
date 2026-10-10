<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task files move to the private disk, served only to people who may see
// the task (TaskFileController). Files already uploaded stay where they
// are -- on the public disk -- and are served the same way.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->string('disk')->default('public')->after('task_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
