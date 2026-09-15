<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Company $company)
    {
        return $company->projects()->with('tasks')->get();
    }

    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        return $company->projects()->create($data);
    }

    public function show(Project $project)
    {
        return $project->load('tasks', 'notes', 'messages', 'invoices', 'transactions', 'company');
    }

    public function update(Request $request, Project $project)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:active,on_hold,completed'],
            'team_names' => ['sometimes', 'array'],
            'team_names.*' => ['string', 'max:255'],
        ]);

        $project->update($data);

        return $project;
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return response()->noContent();
    }
}
