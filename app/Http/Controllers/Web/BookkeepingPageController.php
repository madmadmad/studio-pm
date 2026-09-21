<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookkeepingPageController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->query('month', now()->format('Y-m'));
        [$year, $monthNumber] = explode('-', $month);

        // Income stays here as manually-logged Transaction rows. Expenses
        // are tracked on the dedicated Expenses page now -- this screen
        // only reads their total for the monthly summary.
        $transactions = Transaction::where('type', 'income')->orderByDesc('occurred_on')->get();

        $income = Transaction::where('type', 'income')
            ->whereYear('occurred_on', $year)
            ->whereMonth('occurred_on', $monthNumber)
            ->sum('amount');

        $expenses = Expense::whereYear('date', $year)
            ->whereMonth('date', $monthNumber)
            ->sum('amount');

        return Inertia::render('Bookkeeping/Index', [
            'transactions' => $transactions,
            'summary' => [
                'month' => $month,
                'income' => (float) $income,
                'expenses' => (float) $expenses,
                'net' => (float) $income - (float) $expenses,
            ],
        ]);
    }
}
