<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function index()
    {
        return Tax::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return Tax::create($data);
    }

    public function update(Request $request, Tax $tax)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        $tax->update($data);

        return $tax;
    }

    public function destroy(Tax $tax)
    {
        $tax->delete();

        return response()->noContent();
    }
}
