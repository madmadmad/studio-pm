<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index()
    {
        return Service::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'default_rate' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'in:hourly,fixed'],
            'billable' => ['sometimes', 'boolean'],
        ]);

        // Fresh, so defaults the request left out (billable) come back too --
        // the Services list shows the response as-is.
        return Service::create($data)->refresh();
    }

    public function update(Request $request, Service $service)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'default_rate' => ['sometimes', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'in:hourly,fixed'],
            'billable' => ['sometimes', 'boolean'],
        ]);

        $service->update($data);

        return $service;
    }

    public function destroy(Service $service)
    {
        $service->delete();

        return response()->noContent();
    }
}
