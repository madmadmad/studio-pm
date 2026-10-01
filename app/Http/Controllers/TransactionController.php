<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        return Transaction::query()
            ->where('type', 'income')
            ->when($request->month, fn ($q) => $q->whereMonth('occurred_on', $request->month))
            ->orderByDesc('occurred_on')
            ->get();
    }

    // Income only -- expenses are tracked through the Expense model now,
    // via ExpenseController, so bookkeeping has one source of truth for them.
    public function store(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            // The sales tax included in the amount, if any.
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'category' => ['nullable', 'string'],
            'occurred_on' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'project_id' => ['nullable', 'exists:projects,id'],
        ]);

        $data['type'] = 'income';
        $data['tax_amount'] ??= 0;
        // Manual income gives only the tax it includes; the taxable sales
        // behind it are worked back from the studio's rate.
        $rate = (float) config('invoicing.sales_tax.rate');
        $data['taxable_amount'] = $data['tax_amount'] > 0 && $rate > 0 ? round($data['tax_amount'] / ($rate / 100), 2) : 0;

        return Transaction::create($data);
    }

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return response()->noContent();
    }

    // The "Bookkeeping" screen's summary -- see Transaction::monthlySummary.
    public function summary(Request $request)
    {
        return Transaction::monthlySummary($request->query('month', now()->format('Y-m')));
    }
}
