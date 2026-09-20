<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

class ProjectPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Projects/Index', [
            'projects' => Project::where('status', '!=', 'archived')->with(['company', 'tasks'])->orderBy('name')->get(),
            'companies' => Company::with('contacts')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // A separate section, not just another filter tab on the main list --
    // archived projects are deliberately kept out of the everyday view.
    public function archived(): Response
    {
        return Inertia::render('Projects/Index', [
            'projects' => Project::where('status', 'archived')->with(['company', 'tasks'])->orderBy('name')->get(),
            'companies' => Company::with('contacts')->orderBy('name')->get(['id', 'name']),
            'archivedView' => true,
        ]);
    }

    public function show(Project $project): Response
    {
        $project->load([
            'company.contacts',
            'contact',
            'tasks.subtasks',
            'tasks.files',
            'notes',
            'messages',
            'timeEntries.task',
            'invoices.items',
            'proposals.items',
            'transactions' => fn ($query) => $query->orderByDesc('occurred_on'),
        ]);

        return Inertia::render('Projects/Show', [
            'project' => $project,
        ]);
    }
}
