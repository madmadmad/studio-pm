<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\InvoiceCategory;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Which ledger account each expense category, service and invoice
// category posts to (Bookkeeping > Chart of accounts > Mappings). Null
// means the fallback: Uncategorized Expense for a category, Design &
// Development Services for revenue.
class AccountMappingController extends Controller
{
    public function expenseCategory(Request $request, ExpenseCategory $expenseCategory)
    {
        $data = $request->validate([
            'account_id' => $this->postable(Account::EXPENSE),
            // Where it posts instead when the expense is billed to a client
            // (Advertising → Client Media Spend). Null: the same account.
            'billable_account_id' => $this->postable(Account::EXPENSE),
            // How it reads rebilled on an invoice: where the income goes
            // (null: the line's service or the invoice decides), and
            // whether the line starts out taxable.
            'revenue_account_id' => $this->postable(Account::INCOME),
            'taxable_when_billed' => ['sometimes', 'boolean'],
        ]);

        $expenseCategory->update($data);

        return $expenseCategory->only('id', 'name', 'account_id', 'billable_account_id', 'revenue_account_id', 'taxable_when_billed');
    }

    public function service(Request $request, Service $service)
    {
        $data = $request->validate(['revenue_account_id' => $this->postable(Account::INCOME)]);

        $service->update($data);

        return $service->only('id', 'name', 'revenue_account_id');
    }

    public function invoiceCategory(Request $request, InvoiceCategory $invoiceCategory)
    {
        $data = $request->validate(['revenue_account_id' => $this->postable(Account::INCOME)]);

        $invoiceCategory->update($data);

        return $invoiceCategory->only('id', 'name', 'revenue_account_id');
    }

    // An active account of the type, and not a group heading (nothing
    // posts to those).
    private function postable(string $type): array
    {
        return [
            'sometimes',
            'nullable',
            'integer',
            Rule::exists('accounts', 'id')->where('type', $type)->where('is_active', true),
            function (string $attribute, $value, $fail) {
                if ($value && Account::where('parent_id', $value)->exists()) {
                    $fail('Pick an account, not a group heading.');
                }
            },
        ];
    }
}
