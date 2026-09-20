<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Null parent_id = starts a new thread; set = a reply nested under it.
            $table->foreignId('parent_id')->nullable()->after('project_id')->constrained('messages')->nullOnDelete();
            $table->foreignId('sender_user_id')->nullable()->after('direction')->constrained('users')->nullOnDelete();
            $table->foreignId('sender_contact_id')->nullable()->after('sender_user_id')->constrained('contacts')->nullOnDelete();
            $table->string('subject')->nullable()->change(); // only the thread-starting message needs one
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('sender_user_id');
            $table->dropConstrainedForeignId('sender_contact_id');
        });
    }
};
