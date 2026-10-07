<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\InvoiceCategory;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;

// Bookkeeping > Chart of accounts: the ledger's accounts, and what the
// app's records post to (expense categories, services, invoice
// categories).
class LedgerPageController extends Controller
{
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
