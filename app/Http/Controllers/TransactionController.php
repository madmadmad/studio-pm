<?php

namespace App\Http\Controllers;

use App\Models\Expense;
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
            'category' => ['nullable', 'string'],
            'occurred_on' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'project_id' => ['nullable', 'exists:projects,id'],
        ]);

        $data['type'] = 'income';

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
        [$year, $monthNumber] = explode('-', $month);

        $income = Transaction::where('type', 'income')
            ->whereYear('occurred_on', $year)
            ->whereMonth('occurred_on', $monthNumber)
            ->sum('amount');

        $expenses = Expense::whereYear('date', $year)
            ->whereMonth('date', $monthNumber)
            ->sum('amount');

        return [
            'month' => $month,
            'income' => (float) $income,
            'expenses' => (float) $expenses,
            'net' => (float) $income - (float) $expenses,
        ];
    }
}
