<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Project::class, 'project');
    }

    public function index(Request $request, Company $company)
    {
        $projects = $company->projects()->with('tasks');

        if ($request->user()->isTeamMember()) {
            $projects->whereHas('users', fn ($q) => $q
                ->where('users.id', $request->user()->id)
                ->whereNull('project_user.unassigned_at'));
        }

        return $projects->get();
    }

    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $company->id)],
        ]);

        return $company->projects()->create($data);
    }

    public function show(Project $project)
    {
        return $project->load('tasks', 'notes', 'messages', 'invoices', 'transactions', 'company', 'contact', 'activeUsers');
    }

    public function update(Request $request, Project $project)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'po_number' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:leads,estimated,active,inactive,completed,archived'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $project->company_id)],
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
