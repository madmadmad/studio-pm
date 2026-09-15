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
            'projects' => Project::with(['company', 'tasks'])->orderBy('name')->get(),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Project $project): Response
    {
        $project->load([
            'company',
            'tasks',
            'notes',
            'messages',
            'timeEntries.task',
            'invoices.items',
            'transactions' => fn ($query) => $query->orderByDesc('occurred_on'),
        ]);

        return Inertia::render('Projects/Show', [
            'project' => $project,
        ]);
    }
}
