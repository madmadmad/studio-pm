<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:7'],
        ]);

        return ExpenseCategory::create($data);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:7'],
        ]);

        $expenseCategory->update($data);

        return $expenseCategory;
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        $expenseCategory->delete();

        return response()->noContent();
    }
}
