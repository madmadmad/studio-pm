<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The notification emails' wording as changed in Settings, keyed by
// template then field (App\Services\EmailTemplates); anything not here
// uses config/email_templates.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->json('email_templates')->nullable()->after('invoice_email_message');
        });
    }

    public function down(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->dropColumn('email_templates');
        });
    }
};
