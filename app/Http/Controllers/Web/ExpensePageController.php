<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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
            'expenses' => Expense::with(['category', 'project', 'tax'])->orderByDesc('date')->get(),
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'taxes' => Tax::orderBy('name')->get(),
            'projects' => Project::where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name']),
            'draftInvoices' => Invoice::where('status', 'draft')->orderByDesc('id')->get(['id', 'invoice_number', 'project_id']),
        ]);
    }
}
