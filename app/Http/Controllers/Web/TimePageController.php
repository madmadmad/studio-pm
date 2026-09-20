<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TimePageController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = TimeEntry::with(['company', 'project', 'task']);
        $this->scopeToRole($request, $entries);

        return Inertia::render('Time/Index', [
            'timeEntries' => $entries->orderByDesc('date')->get(),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'projects' => $this->visibleProjects($request)->get(['id', 'company_id', 'name']),
        ]);
    }

    public function weekly(Request $request): Response
    {
        $start = Carbon::parse($request->query('week_start', now()->startOfWeek()->toDateString()))->startOfDay();
        $end = (clone $start)->endOfWeek();

        $entries = TimeEntry::with(['company', 'project', 'task'])->whereBetween('date', [$start, $end]);
        $this->scopeToRole($request, $entries);

        return Inertia::render('Time/Weekly', [
            'weekStart' => $start->toDateString(),
            'weekEnd' => $end->toDateString(),
            'entries' => $entries->orderBy('date')->get(),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // A Team Member only logs/sees time against a project they're (or were)
    // assigned to -- same rule the API's TimeEntryController enforces.
    protected function scopeToRole(Request $request, $query): void
    {
        if ($request->user()->isTeamMember()) {
            $query->whereHas('project.users', fn ($q) => $q->where('users.id', $request->user()->id));
        }
    }

    protected function visibleProjects(Request $request)
    {
        $projects = Project::query();

        if ($request->user()->isTeamMember()) {
            $projects->whereHas('users', fn ($q) => $q
                ->where('users.id', $request->user()->id)
                ->whereNull('project_user.unassigned_at'));
        }

        return $projects->orderBy('name');
    }
}
