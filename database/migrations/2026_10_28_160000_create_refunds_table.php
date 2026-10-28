<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Money given back to a client on an invoice they paid (CPA review, Oct
// 2026): recorded here once it's been sent back (by check, or from Stripe's
// dashboard for a card), and taken back off the revenue it was booked to
// (RefundPoster). Each invoice payment's and refund's income row now names
// it, so undoing one takes its row with it; existing rows are matched to
// their payment by invoice and amount.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('method'); // check, ach, card, other
            $table->decimal('amount', 10, 2);
            // The 3% card fee given back with a full card refund.
            $table->decimal('surcharge_amount', 10, 2)->default(0);
            $table->string('reason')->nullable();
            $table->timestamp('refunded_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('refund_id')->nullable()->after('payment_id')->constrained()->cascadeOnDelete();
        });

        foreach (DB::table('payments')->orderBy('id')->get() as $payment) {
            $row = DB::table('transactions')
                ->where('invoice_id', $payment->invoice_id)->whereNull('payment_id')->where('type', 'income')
                ->where('amount', $payment->amount)->orderBy('id')->first();
            if ($row) {
                DB::table('transactions')->where('id', $row->id)->update(['payment_id' => $payment->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_id');
            $table->dropConstrainedForeignId('payment_id');
        });
        Schema::dropIfExists('refunds');
    }
};
