<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Every invoice has a concrete term, unlike the client-level
            // default (which can be left unset to mean "use the firm
            // default") -- so this is not-null with a real fallback, not
            // nullable. Existing invoices were all created under the old
            // hardcoded Net 30 behavior this replaces, so that's the
            // correct backfill value, not just a placeholder.
            $table->string('payment_terms')->default('net_30')->after('due_on');
        });

        // Defensive only: every existing invoice already has both dates set
        // by the controller at creation time, so this loop should touch
        // zero rows -- cheap insurance against any invoice created before
        // issued_on/due_on existed or through a path that skipped them.
        // Done in PHP rather than raw SQL date arithmetic so it works the
        // same regardless of which database engine is behind this app.
        DB::table('invoices')
            ->where(fn ($query) => $query->whereNull('due_on')->orWhereNull('issued_on'))
            ->orderBy('id')
            ->cursor()
            ->each(function ($invoice) {
                $issuedOn = $invoice->issued_on ?? Carbon::parse($invoice->created_at)->toDateString();
                $dueOn = $invoice->due_on ?? Carbon::parse($issuedOn)->addDays(30)->toDateString();

                DB::table('invoices')->where('id', $invoice->id)->update([
                    'issued_on' => $issuedOn,
                    'due_on' => $dueOn,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_terms');
        });
    }
};
