<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Message;
use App\Models\Project;
use App\Models\Proposal;
use App\Services\MessageVersion;
use App\Services\UnreadMessages;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProjectPageController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Projects/Index', [
            'projects' => static::boardProjects($request),
            // For New project.
            'companies' => $request->user()->hasPermission('manage_projects')
                ? Company::with('contacts')->orderBy('name')->get(['id', 'name'])
                : [],
            // The money figures across the top -- with the Invoices
            // permission, like the rest of the billing.
            'metrics' => $request->user()->hasPermission('invoices') ? $this->metrics() : null,
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
        abort_unless($request->user()->hasPermission('all_projects'), 403);

        return Inertia::render('Projects/Index', [
            'projects' => Project::where('status', 'archived')->with(['company', 'tasks'])->orderBy('name')->get()
                ->each(fn (Project $project) => $request->user()->hasPermission('invoices') ? null : $project->makeHidden('budget')),
            'companies' => [],
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

        // The project's money, each part by its permission. Everyone on the
        // project sees its approved (accepted) proposals in full; drafts and
        // the rest take the Proposals permission.
        $user = $request->user();
        $project->load(['proposals' => fn ($query) => $query
            ->when(! $user->hasPermission('proposals'), fn ($q) => $q->where('status', 'accepted'))
            ->with('items')]);
        $project->proposals->each(fn ($proposal) => $proposal->setAttribute('team', $proposal->teamSection())->setAttribute('about', $proposal->aboutSection()));
        if ($user->hasPermission('invoices')) {
            $project->load([
                'invoices.items',
                'transactions' => fn ($query) => $query->orderByDesc('occurred_on'),
            ]);
        } else {
            $project->makeHidden('budget');
        }
        if ($user->hasPermission('expenses')) {
            $project->load(['expenses' => fn ($query) => $query->with('category')->orderByDesc('date')]);
        }

        return Inertia::render('Projects/Show', [
            'project' => $project,
            // Where the Messages tab's "anything new?" check starts from.
            'messagesVersion' => MessageVersion::of($project->messages()),
            'canManageTeam' => $request->user()->can('manage', $project),
            // Which money tabs and actions to show (the server sends their
            // data only to those with the permission, too).
            'can' => [
                'proposals' => $user->hasPermission('proposals'),
                'invoices' => $user->hasPermission('invoices'),
                'expenses' => $user->hasPermission('expenses'),
            ],
            // The header's settings gear (name, status, contact, PO...).
            'canEdit' => $request->user()->can('manage', $project),
            // For the Hours remaining card: hours sold in accepted proposals.
            // Sent to everyone (proposals themselves are manager-only).
            'proposedHours' => $project->proposedHours(),
            // Every active staff account is assignable, regardless of role --
            // a manager can be put on a project's roster too (for messaging,
            // visibility, etc.), not just team members.
            'assignableStaff' => $request->user()->can('manage', $project)
                ? User::whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                : [],
            // Line-item presets for the proposal drawer (with the Proposals
            // permission, like writing them).
            'services' => $request->user()->hasPermission('proposals') ? Service::orderBy('name')->get() : [],
            // For logging time: everyone picks a service; the rates above
            // stay manager-only, so this is just name and billable.
            'timeServices' => Service::orderBy('name')->get(['id', 'name', 'billable']),
        ]);
    }

    // The projects for a list or board (the Projects page; the profile's
    // starred board with `$starredOnly`): every one that isn't archived and
    // this person can see, with its client and tasks, whether they've
    // starred it, its unread message threads for them and -- with Invoices
    // -- what it has billed so far, before tax (the board's budget bar).
    public static function boardProjects(Request $request, bool $starredOnly = false): Collection
    {
        $user = $request->user();
        $controller = new static;

        $projects = Project::where('status', '!=', 'archived')->with(['company', 'tasks']);
        $controller->scopeToRole($request, $projects);
        $controller->withFavorite($request, $projects);
        if ($starredOnly) {
            $projects->whereHas('favoritedBy', fn ($q) => $q->where('users.id', $user->id));
        }
        $seesMoney = $user->hasPermission('invoices');
        if ($seesMoney) {
            $projects->withSum('invoiceItems as invoiced_amount', 'amount');
        }

        $unread = UnreadMessages::countsByProject($user);

        // Budgets stay with those who see the billing.
        return $projects->orderBy('name')->get()
            ->each(fn (Project $project) => $project->setAttribute('unread_messages', $unread[$project->id] ?? 0))
            ->each(fn (Project $project) => $seesMoney ? null : $project->makeHidden('budget'));
    }

    // `is_favorite`: whether the viewer has starred each project.
    protected function withFavorite(Request $request, $query): void
    {
        $query->withExists(['favoritedBy as is_favorite' => fn ($q) => $q->where('users.id', $request->user()->id)]);
    }

    protected function scopeToRole(Request $request, $query): void
    {
        if (! $request->user()->hasPermission('all_projects')) {
            $query->whereHas('users', fn ($q) => $q
                ->where('users.id', $request->user()->id)
                ->whereNull('project_user.unassigned_at'));
        }
    }
}
