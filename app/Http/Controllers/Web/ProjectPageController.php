<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectPageController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::where('status', '!=', 'archived')->with(['company', 'tasks']);
        $this->scopeToRole($request, $projects);

        return Inertia::render('Projects/Index', [
            'projects' => $projects->orderBy('name')->get(),
            'companies' => $request->user()->isManager()
                ? Company::with('contacts')->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    // A separate section, not just another filter tab on the main list --
    // archived projects are deliberately kept out of the everyday view.
    public function archived(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);
        abort_unless($request->user()->isManager(), 403);

        return Inertia::render('Projects/Index', [
            'projects' => Project::where('status', 'archived')->with(['company', 'tasks'])->orderBy('name')->get(),
            'companies' => Company::with('contacts')->orderBy('name')->get(['id', 'name']),
            'archivedView' => true,
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $project->load([
            'company.contacts',
            'contact',
            'tasks.subtasks',
            'tasks.files',
            'notes',
            'messages' => fn ($q) => $q->with([
                'senderUser', 'senderContact',
                'participants.user', 'participants.contact',
                'replies.senderUser', 'replies.senderContact',
            ]),
            'timeEntries.task',
            'activeUsers:id,name,email',
        ]);

        // Firm financials on a project stay Manager-only, even for a Team
        // Member who's otherwise allowed to see this project's page.
        if ($request->user()->isManager()) {
            $project->load([
                'invoices.items',
                'proposals.items',
                'transactions' => fn ($query) => $query->orderByDesc('occurred_on'),
                'expenses' => fn ($query) => $query->with('category')->orderByDesc('date'),
            ]);
        }

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'canManageTeam' => $request->user()->isManager(),
            // Every active staff account is assignable, regardless of role --
            // a manager can be put on a project's roster too (for messaging,
            // visibility, etc.), not just team members.
            'assignableStaff' => $request->user()->isManager()
                ? User::whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    protected function scopeToRole(Request $request, $query): void
    {
        if ($request->user()->isTeamMember()) {
            $query->whereHas('users', fn ($q) => $q
                ->where('users.id', $request->user()->id)
                ->whereNull('project_user.unassigned_at'));
        }
    }
}
