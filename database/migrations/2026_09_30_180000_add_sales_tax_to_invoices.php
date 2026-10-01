<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sales tax, switched on per invoice and charged on the lines marked
// taxable. The name and rate are copied onto the invoice when tax is
// turned on (config/invoicing.php's sales_tax), so a later rate change
// never alters an invoice already sent; a null rate means no tax.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('tax_name')->nullable()->after('surcharge');
            $table->decimal('tax_rate', 6, 3)->nullable()->after('tax_name');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('taxable')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('taxable');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['tax_name', 'tax_rate']);
        });
    }
};
