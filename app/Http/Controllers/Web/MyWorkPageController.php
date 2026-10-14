<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TodoController;
use App\Models\Company;
use App\Models\Project;
use App\Models\Service;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

// My Work: the signed-in person's own work, where they land after signing
// in -- their tasks (assigned or starred), their private to-do list, their
// projects (recently worked on, and starred), and their week's time.
class MyWorkPageController extends Controller
{
    // How far back "Recently worked on" looks.
    const RECENT_DAYS = 30;

    public function index(Request $request): Response
    {
        return Inertia::render('MyWork/Index', [
            'myTasks' => fn () => $this->myTasks($request),
            // Their own to-do list: open ones in order, then those ticked.
            'todos' => fn () => TodoController::listFor($request->user()->id),
            'recentProjects' => fn () => $this->recentProjects($request),
            // The Projects tab's board: the projects they've starred.
            'starredProjects' => fn () => ProjectPageController::boardProjects($request, starredOnly: true),
            'timesheet' => fn () => $this->timesheet($request),
            // For logging time: the projects this person can log against
            // (with their tasks), their clients, and the services.
            'timeProjects' => fn () => $this->loggableProjects($request),
            'timeCompanies' => fn () => Company::orderBy('name')->get(['id', 'name']),
            'timeServices' => fn () => Service::orderBy('name')->get(['id', 'name', 'billable']),
        ]);
    }

    // The projects they've logged time on lately, most recent first: when
    // they last did, and their hours on it over the period. Only projects
    // they can still see.
    protected function recentProjects(Request $request)
    {
        $user = $request->user();
        $since = today()->subDays(self::RECENT_DAYS - 1);

        $totals = TimeEntry::where('user_id', $user->id)
            ->whereNotNull('project_id')
            ->where('date', '>=', $since)
            ->groupBy('project_id')
            ->selectRaw('project_id, max(date) as last_worked_on, sum(hours) as hours')
            ->get()
            ->keyBy('project_id');

        return Project::whereKey($totals->keys())
            ->with('company:id,name')
            ->get(['id', 'name', 'status', 'company_id'])
            ->filter(fn (Project $project) => $user->can('view', $project))
            ->map(fn (Project $project) => [
                ...$project->toArray(),
                'last_worked_on' => Carbon::parse($totals[$project->id]->last_worked_on)->toDateString(),
                'hours' => round((float) $totals[$project->id]->hours, 2),
            ])
            ->sortByDesc('last_worked_on')
            ->values();
    }

    // The signed-in person's own time for one week (Monday to Sunday,
    // ?week=YYYY-MM-DD, any day in it; this week by default): every day,
    // with its entries and hours, and the week's totals. Each entry says
    // whether they can still change it (TimeEntryPolicy).
    protected function timesheet(Request $request): array
    {
        $user = $request->user();
        $day = rescue(fn () => Carbon::parse($request->query('week', today()->toDateString())), today(), false);
        $start = $day->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

        $entries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('date', [$start, $end])
            ->with(['company:id,name', 'project:id,name,company_id', 'project.tasks:id,project_id,title', 'task:id,title', 'service:id,name,billable', 'user:id,name,avatar_path'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->each(fn (TimeEntry $entry) => $entry->setAttribute('can_edit', $user->can('update', $entry)));

        $byDay = $entries->groupBy(fn (TimeEntry $entry) => $entry->date->toDateString());
        $days = collect(range(0, 6))->map(function ($offset) use ($start, $byDay) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $dayEntries = $byDay->get($date, collect())->values();

            return ['date' => $date, 'hours' => round((float) $dayEntries->sum('hours'), 2), 'entries' => $dayEntries];
        });

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'is_this_week' => $start->isSameDay(today()->startOfWeek(Carbon::MONDAY)),
            'days' => $days,
            'totals' => [
                'hours' => round((float) $entries->sum('hours'), 2),
                'billable' => round((float) $entries->where('billable', true)->sum('hours'), 2),
                'entries' => $entries->count(),
            ],
        ];
    }

    // The Tasks tab: the tasks assigned to this person (a task's assignee
    // is a name, so by their name) and the ones they've starred, that they
    // can still see. Assigned tasks on archived projects drop out, and done
    // ones after a week; starred ones stay until unstarred. Open ones come
    // first, soonest due first (undated last), then the done ones. Each
    // says whether it's starred, whether it's theirs, and whether they can
    // change its status here (TaskPolicy).
    protected function myTasks(Request $request)
    {
        $user = $request->user();
        $starredIds = $user->favoriteTasks()->pluck('tasks.id');

        return Task::query()
            ->where(fn ($q) => $q
                ->whereIn('id', $starredIds)
                ->orWhere(fn ($q) => $q
                    ->where('assignee', $user->name)
                    ->whereHas('project', fn ($q) => $q->where('status', '!=', 'archived'))
                    ->where(fn ($q) => $q->where('status', '!=', 'done')->orWhere('updated_at', '>=', now()->subWeek()))))
            ->with('project:id,name,company_id,status', 'project.company:id,name')
            ->get(['id', 'project_id', 'title', 'status', 'due_date', 'assignee'])
            ->filter(fn (Task $task) => $user->can('view', $task))
            ->sortBy(fn (Task $task) => [$task->status === 'done' ? 1 : 0, $task->due_date ? 0 : 1, $task->due_date?->toDateString() ?? '', $task->id])
            ->values()
            ->each(fn (Task $task) => $task
                ->setAttribute('is_favorite', $starredIds->contains($task->id))
                ->setAttribute('is_assigned', $task->assignee === $user->name)
                ->setAttribute('can_edit', $user->can('update', $task)));
    }

    // A team member logs time on projects they're on now; a manager on any
    // that isn't archived (as on a project's Time tab).
    protected function loggableProjects(Request $request)
    {
        $projects = Project::where('status', '!=', 'archived')->with('tasks:id,project_id,title')->orderBy('name');

        if (! $request->user()->hasPermission('all_projects')) {
            $projects->whereHas('users', fn ($q) => $q
                ->where('users.id', $request->user()->id)
                ->whereNull('project_user.unassigned_at'));
        }

        return $projects->get(['id', 'company_id', 'name']);
    }
}
