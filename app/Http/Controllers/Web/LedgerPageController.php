<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\InvoiceCategory;
use App\Models\JournalEntry;
use App\Models\Service;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// The ledger's own screens: the Journal, and the Chart of accounts with
// what the app's records post to (expense categories, services, invoice
// categories).
class LedgerPageController extends Controller
{
    // Bookkeeping > Journal: the year's entries, newest first, with what
    // the entry forms need -- the accounts, the clients, what's waiting in
    // Stripe Clearing -- and the locked periods for a super admin.
    public function journal(Request $request): Response
    {
        $years = JournalEntry::pluck('entry_date')->map(fn ($date) => $date->year)
            ->push(now()->year)->unique()->sortDesc()->values();
        $year = (int) $request->query('year', $years->first());
        $canLock = $request->user()->hasPermission('settings');

        return Inertia::render('Bookkeeping/Journal', [
            'year' => $year,
            'years' => $years,
            'entries' => JournalEntry::whereYear('entry_date', $year)
                ->with(['lines.account', 'lines.company', 'source', 'creator', 'reverses', 'reversal'])
                ->orderByDesc('entry_date')->orderByDesc('entry_number')
                ->get()
                ->map->summary(),
            'accounts' => Account::orderBy('code')->get(['id', 'code', 'name', 'type', 'system_key', 'parent_id', 'is_active']),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'clearingCents' => Account::forKey('stripe_clearing')->netDebitCents(),
            'canLock' => $canLock,
            'periods' => $canLock ? AccountingPeriod::with('locker:id,name')->orderByDesc('starts_on')->get() : [],
        ]);
    }

    public function accounts(): Response
    {
        return Inertia::render('Bookkeeping/Accounts', [
            'accounts' => Account::orderBy('code')->get(['id', 'code', 'code_is_placeholder', 'name', 'type', 'system_key', 'parent_id', 'description', 'is_active']),
            'expenseCategories' => ExpenseCategory::orderBy('name')->get(['id', 'name', 'account_id', 'billable_account_id', 'revenue_account_id', 'taxable_when_billed']),
            'services' => Service::orderBy('name')->get(['id', 'name', 'revenue_account_id']),
            'invoiceCategories' => InvoiceCategory::orderBy('name')->get(['id', 'name', 'revenue_account_id']),
            // What an unmapped record falls back to.
            'fallbacks' => [
                'expense' => Account::forKey('uncategorized_expense')->name,
                'revenue' => Account::forKey('service_revenue')->name,
            ],
        ]);
    }
}
