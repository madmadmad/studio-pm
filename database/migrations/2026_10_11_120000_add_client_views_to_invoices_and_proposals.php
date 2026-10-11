<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When the client opens an invoice's or proposal's link (App\Support\
// DocumentViews), who sent a proposal (so they hear about it), and
// whether each staff member wants to.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'proposals'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('first_viewed_at')->nullable();
                $table->timestamp('last_viewed_at')->nullable();
                $table->unsignedInteger('view_count')->default(0);
            });
        }

        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_on_document_open')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_on_document_open');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by_user_id');
        });

        foreach (['invoices', 'proposals'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['first_viewed_at', 'last_viewed_at', 'view_count']);
            });
        }
    }
};
