<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// What happens to an invoice's money after it's paid: a refund to the
// client, recorded once it's been sent back (by check, or from Stripe's
// dashboard for a card -- the app doesn't send it); and, for a super admin,
// undoing a payment or refund recorded by mistake. The ledger follows each
// record (RefundPoster, PaymentPoster), so undoing one reverses its entry.
class InvoicePaymentController extends Controller
{
    // Up to what was paid, less earlier refunds. The invoice stays paid.
    // A card refund of everything that's left gives back the 3% card fee
    // too; a partial one keeps it.
    public function refund(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'refunded_on' => ['nullable', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:check,ach,card,other'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['refunded_on.before_or_equal' => 'The refund date can’t be in the future.']);

        $invoice->load('payments', 'refunds');
        $refundable = $invoice->refundableAmount();
        abort_if($refundable < 0.005, 422, 'Nothing paid on this invoice is left to refund.');
        $amount = round((float) $data['amount'], 2);
        abort_if($amount - $refundable > 0.005, 422, 'That’s more than was paid ($'.number_format($refundable, 2).').');

        $fee = 0.0;
        if ($data['method'] === 'card' && abs($amount - $refundable) < 0.005) {
            $fee = round((float) $invoice->payments->sum('surcharge_amount') - (float) $invoice->refunds->sum('surcharge_amount'), 2);
        }

        $refundedOn = $data['refunded_on'] ?? null;
        $refundedAt = $refundedOn && ! Carbon::parse($refundedOn)->isToday() ? Carbon::parse($refundedOn)->setTime(12, 0) : now();

        DB::transaction(function () use ($invoice, $data, $amount, $fee, $refundedAt, $request) {
            $refund = $invoice->refunds()->create([
                'method' => $data['method'],
                'amount' => $amount,
                'surcharge_amount' => $fee,
                'reason' => $data['reason'] ?? null,
                'refunded_at' => $refundedAt,
                'created_by' => $request->user()->id,
            ]);

            // The income record runs negative, with its share of the tax,
            // so the older reports net it out.
            $total = $invoice->total();
            $share = $total > 0 ? min(1, $amount / $total) : 1;
            Transaction::create([
                'type' => 'income',
                'amount' => -$amount,
                'tax_amount' => -round($invoice->taxAmount() * $share, 2),
                'taxable_amount' => -round($invoice->taxableSubtotal() * $share, 2),
                'category' => Transaction::CLIENT_INVOICE,
                'description' => 'Refund'.($data['reason'] ?? null ? ': '.$data['reason'] : ''),
                'occurred_on' => $refundedAt,
                'invoice_id' => $invoice->id,
                'refund_id' => $refund->id,
                'project_id' => $invoice->project_id,
            ]);
        });

        return $invoice->fresh()->loadForDetail();
    }

    // A payment that never came in, or was entered wrong. Refused while
    // the refunds would then come to more than was paid.
    public function undoPayment(Invoice $invoice, Payment $payment)
    {
        abort_unless($payment->invoice_id === $invoice->id, 404);
        $invoice->load('payments', 'refunds');
        abort_if($invoice->refundableAmount() - (float) $payment->amount < -0.005, 422, 'Undo the refunds on this invoice first.');

        DB::transaction(function () use ($invoice, $payment) {
            Transaction::where('payment_id', $payment->id)->delete();
            $payment->delete();

            $invoice->unsetRelation('payments');
            if ($invoice->status === 'paid' && ! $invoice->isPaidInFull()) {
                $invoice->update(['status' => 'sent']);
                $invoice->expenses()->where('billing_status', 'billed_and_paid')->update(['billing_status' => 'billed']);
            }
        });

        return $invoice->fresh()->loadForDetail();
    }

    public function undoRefund(Invoice $invoice, Refund $refund)
    {
        abort_unless($refund->invoice_id === $invoice->id, 404);

        DB::transaction(function () use ($refund) {
            Transaction::where('refund_id', $refund->id)->delete();
            $refund->delete();
        });

        return $invoice->fresh()->loadForDetail();
    }
}
