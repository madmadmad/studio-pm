<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Message;
use App\Models\Project;
use App\Models\Proposal;
use App\Services\UnreadMessages;
use App\Models\Service;
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
        $this->withFavorite($request, $projects);
        // What each has billed so far, before tax (the board's budget bar)
        // -- managers only, like the other money figures.
        if ($request->user()->isManager()) {
            $projects->withSum('invoiceItems as invoiced_amount', 'amount');
        }

        // Unread message threads on each, for this person (UnreadMessages).
        $unread = UnreadMessages::countsByProject($request->user());

        return Inertia::render('Projects/Index', [
            'projects' => $projects->orderBy('name')->get()
                ->each(fn (Project $project) => $project->setAttribute('unread_messages', $unread[$project->id] ?? 0)),
            'companies' => $request->user()->isManager()
                ? Company::with('contacts')->orderBy('name')->get(['id', 'name'])
                : [],
            // The money figures across the top -- managers only, like the
            // rest of the billing.
            'metrics' => $request->user()->isManager() ? $this->metrics() : null,
        ]);
    }

    // Each a count and an amount: active projects and their budgets, the
    // open proposals on estimated ones, and what active budgets have left
    // to invoice (before tax, which isn't billed work).
    private function metrics(): array
    {
        $active = Project::where('status', 'active')->with('invoices.items')->get();
        $leftToInvoice = $active
            ->map(fn (Project $project) => max((float) $project->budget - (float) $project->invoices->flatMap->items->sum('amount'), 0))
            ->filter(fn ($left) => $left > 0);

        $openProposals = Proposal::whereIn('status', ['draft', 'sent'])
            ->whereHas('project', fn ($q) => $q->where('status', 'estimated'));

        return [
            'active' => ['count' => $active->count(), 'amount' => round((float) $active->sum('budget'), 2)],
            'estimated' => [
                'count' => Project::where('status', 'estimated')->count(),
                'amount' => round((float) (clone $openProposals)->sum('estimate_amount'), 2),
            ],
            'left_to_invoice' => ['count' => $leftToInvoice->count(), 'amount' => round($leftToInvoice->sum(), 2)],
        ];
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

        $project->is_favorite = $request->user()->favoriteProjects()->whereKey($project->id)->exists();
        $project->load([
            'company.contacts',
            'contact',
            'tasks.subtasks',
            'tasks.files',
            'notes.user',
            'scheduleItems',
            'messages' => fn ($q) => $q->withTrashed()->with(Message::threadRelations()),
            'timeEntries.task',
            // Whose time each entry is (the Time tab's Team member column).
            'timeEntries.user:id,name,avatar_path',
            'timeEntries.service:id,name,billable',
            'activeUsers:id,name,email,role,avatar_path',
        ]);

        UnreadMessages::mark($project->messages, $request->user());

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
            // The header's settings gear (name, status, contact, PO...).
            'canEdit' => $request->user()->can('update', $project),
            // For the Hours remaining card: hours sold in accepted proposals.
            // Sent to everyone (proposals themselves are manager-only).
            'proposedHours' => $project->proposedHours(),
            // Every active staff account is assignable, regardless of role --
            // a manager can be put on a project's roster too (for messaging,
            // visibility, etc.), not just team members.
            'assignableStaff' => $request->user()->isManager()
                ? User::whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                : [],
            // Line-item presets for the proposal drawer (Manager-only, like
            // the proposals themselves).
            'services' => $request->user()->isManager() ? Service::orderBy('name')->get() : [],
            // For logging time: everyone picks a service; the rates above
            // stay manager-only, so this is just name and billable.
            'timeServices' => Service::orderBy('name')->get(['id', 'name', 'billable']),
        ]);
    }

    // `is_favorite`: whether the viewer has starred each project.
    protected function withFavorite(Request $request, $query): void
    {
        $query->withExists(['favoritedBy as is_favorite' => fn ($q) => $q->where('users.id', $request->user()->id)]);
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
