<?php

namespace App\Http\Controllers;

use App\Models\InvoiceCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// The invoice categories list in Settings. Deleting one leaves its
// invoices as project work (the foreign key nulls out).
class InvoiceCategoryController extends Controller
{
    public function index()
    {
        return InvoiceCategory::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:invoice_categories,name', Rule::notIn([InvoiceCategory::PROJECT_WORK])]]);

        return InvoiceCategory::create($data);
    }

    public function update(Request $request, InvoiceCategory $invoiceCategory)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('invoice_categories', 'name')->ignore($invoiceCategory->id), Rule::notIn([InvoiceCategory::PROJECT_WORK])]]);

        $invoiceCategory->update($data);

        return $invoiceCategory;
    }

    public function destroy(InvoiceCategory $invoiceCategory)
    {
        $invoiceCategory->delete();

        return response()->noContent();
    }
}
