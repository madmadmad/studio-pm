<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        return Expense::query()
            ->with(['category', 'project', 'tax'])
            ->when($request->search, fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->when($request->project_id, fn ($q) => $q->where('project_id', $request->project_id))
            ->when($request->billing_status, fn ($q) => $q->where('billing_status', $request->billing_status))
            ->orderByDesc('date')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if (! empty($data['is_billable']) && empty($data['project_id'])) {
            abort(422, 'A billable expense must be tied to a project.');
        }

        if ($request->hasFile('receipt')) {
            $data['receipt_path'] = $request->file('receipt')->store('expense-receipts', 'public');
            $data['receipt_filename'] = $request->file('receipt')->getClientOriginalName();
        }

        return Expense::create($data)->load(['category', 'project', 'tax']);
    }

    public function update(Request $request, Expense $expense)
    {
        abort_unless($expense->billing_status === 'unbilled', 422, 'Billed expenses cannot be edited -- detach from the invoice first.');

        $data = $this->validated($request, $expense);

        $isBillable = $data['is_billable'] ?? $expense->is_billable;
        $projectId = $data['project_id'] ?? $expense->project_id;
        if ($isBillable && ! $projectId) {
            abort(422, 'A billable expense must be tied to a project.');
        }

        if ($request->hasFile('receipt')) {
            if ($expense->receipt_path) {
                Storage::disk('public')->delete($expense->receipt_path);
            }
            $data['receipt_path'] = $request->file('receipt')->store('expense-receipts', 'public');
            $data['receipt_filename'] = $request->file('receipt')->getClientOriginalName();
        }

        $expense->update($data);

        return $expense->fresh()->load(['category', 'project', 'tax']);
    }

    public function destroy(Expense $expense)
    {
        abort_unless($expense->billing_status === 'unbilled', 422, 'Billed expenses cannot be deleted -- detach from the invoice first.');

        if ($expense->receipt_path) {
            Storage::disk('public')->delete($expense->receipt_path);
        }

        $expense->delete();

        return response()->noContent();
    }

    public function attachToInvoice(Request $request, Expense $expense)
    {
        abort_unless($expense->billing_status === 'unbilled', 422, 'This expense is already attached to an invoice.');

        $data = $request->validate([
            'invoice_id' => ['required', Rule::exists('invoices', 'id')],
        ]);

        $invoice = Invoice::findOrFail($data['invoice_id']);
        abort_unless($invoice->status === 'draft', 422, 'Expenses can only be attached to a draft invoice.');

        $expense->attachToInvoice($invoice);

        return $expense->fresh()->load(['category', 'project', 'tax']);
    }

    public function detachFromInvoice(Expense $expense)
    {
        abort_if($expense->billing_status === 'billed_and_paid', 422, 'A paid expense cannot be detached.');
        abort_if($expense->billing_status === 'unbilled', 422, 'This expense is not attached to an invoice.');

        $expense->detachFromInvoice();

        return $expense->fresh()->load(['category', 'project', 'tax']);
    }

    private function validated(Request $request, ?Expense $expense = null): array
    {
        return $request->validate([
            'name' => [$expense ? 'sometimes' : 'required', 'string', 'max:255'],
            'amount' => [$expense ? 'sometimes' : 'required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'category_id' => ['nullable', 'exists:expense_categories,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'is_billable' => ['boolean'],
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
            'tax_id' => ['nullable', 'exists:taxes,id'],
            'date' => [$expense ? 'sometimes' : 'required', 'date'],
            'is_recurring' => ['boolean'],
            'recurrence_interval' => ['nullable', 'in:weekly,monthly,quarterly,yearly'],
            'source_label' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
