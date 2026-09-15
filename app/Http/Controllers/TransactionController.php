<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        return Transaction::query()
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->month, fn ($q) => $q->whereMonth('occurred_on', $request->month))
            ->orderByDesc('occurred_on')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['nullable', 'string'],
            'occurred_on' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        return Transaction::create($data);
    }

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return response()->noContent();
    }

    // The "Bookkeeping" screen -- a simple income vs. expense summary,
    // not double-entry accounting. Good enough for a P&L-style glance,
    // not for anything an accountant needs to file from.
    public function summary(Request $request)
    {
        $month = $request->query('month', now()->format('Y-m'));

        $income = Transaction::where('type', 'income')
            ->whereRaw("DATE_FORMAT(occurred_on, '%Y-%m') = ?", [$month])
            ->sum('amount');

        $expenses = Transaction::where('type', 'expense')
            ->whereRaw("DATE_FORMAT(occurred_on, '%Y-%m') = ?", [$month])
            ->sum('amount');

        return [
            'month' => $month,
            'income' => (float) $income,
            'expenses' => (float) $expenses,
            'net' => (float) $income - (float) $expenses,
        ];
    }
}
