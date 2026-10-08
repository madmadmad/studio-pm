<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Tax;
use Inertia\Inertia;
use Inertia\Response;

class ExpensePageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Expenses/Index', [
            'expenses' => Expense::with(['category', 'project', 'tax', 'splits.company:id,name'])->orderByDesc('date')->get(),
            // For splitting a shared cost: the clients, and the recent
            // splits -- a new bill starts from the one with its name and
            // the nearest total (two Linode accounts bill under one name).
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'lastSplits' => Expense::has('splits')->with('splits:id,expense_id,company_id,amount')->latest('date')->latest('id')->limit(12)->get()
                ->map(fn (Expense $e) => $e->only('id', 'name', 'amount', 'splits')),
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'taxes' => Tax::orderBy('name')->get(),
            'projects' => Project::where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name']),
            'draftInvoices' => Invoice::where('status', 'draft')->orderByDesc('id')->get(['id', 'invoice_number', 'project_id']),
            // What an expense can be paid from: the bank and card accounts
            // (not Stripe Clearing or Sales Tax Payable, which nothing is
            // bought with).
            'paymentAccounts' => Account::active()
                ->whereIn('type', [Account::ASSET, Account::LIABILITY])
                ->whereNotNull('parent_id')
                ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', ['stripe_clearing', 'sales_tax_payable']))
                ->orderBy('code')
                ->get(['id', 'name']),
            'defaultPaymentAccountId' => Account::forKey('capital_one_card')->id,
        ]);
    }
}
