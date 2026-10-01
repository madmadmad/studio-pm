<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The estimate disclaimer new proposals start with, and the sales tax
// invoices can charge, editable in Settings -- seeded from the config
// defaults they used to be read from. A blank disclaimer means none; no
// rate means no tax to offer.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->text('proposal_disclaimer')->nullable()->after('payment_instructions');
            $table->string('sales_tax_name')->nullable()->after('proposal_disclaimer');
            $table->decimal('sales_tax_rate', 6, 3)->nullable()->after('sales_tax_name');
        });

        DB::table('studio_profiles')->update([
            'proposal_disclaimer' => config('proposals.default_disclaimer'),
            'sales_tax_name' => config('invoicing.sales_tax.name'),
            'sales_tax_rate' => config('invoicing.sales_tax.rate'),
        ]);
    }

    public function down(): void
    {
        Schema::table('studio_profiles', function (Blueprint $table) {
            $table->dropColumn(['proposal_disclaimer', 'sales_tax_name', 'sales_tax_rate']);
        });
    }
};
