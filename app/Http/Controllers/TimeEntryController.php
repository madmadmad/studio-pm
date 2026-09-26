<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Service;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TimeEntryController extends Controller
{
    // Raw log -- used by the Time Tracking screen.
    public function index(Request $request)
    {
        return $this->scopeToRole($request, TimeEntry::query())
            ->when($request->company_id, fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->billed !== null, fn ($q) => $q->where('billed', $request->boolean('billed')))
            ->orderByDesc('date')
            ->get();
    }

    // Time is logged inside a project, against one of its team: a manager
    // can log for anyone currently on the project (themselves by default);
    // a team member always logs their own.
    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'task_id' => ['nullable', Rule::exists('tasks', 'id')->where('project_id', $request->input('project_id'))],
            'user_id' => ['nullable', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.25'],
            'note' => ['nullable', 'string'],
        ]);

        $project = Project::findOrFail($data['project_id']);
        $this->authorize('create', [TimeEntry::class, $project]);

        $data['company_id'] = $project->company_id;
        $data['user_id'] = $this->teamMemberFor($request, $project, $data['user_id'] ?? null);
        $data = $this->withServiceBillable($data);

        return TimeEntry::create($data)->load('user:id,name,avatar_path', 'task', 'service:id,name,billable');
    }

    // An entry for a service takes the service's billable setting (an
    // explicit billable in the same request still wins, as an override).
    private function withServiceBillable(array $data): array
    {
        if (! empty($data['service_id']) && ! array_key_exists('billable', $data)) {
            $data['billable'] = Service::findOrFail($data['service_id'])->billable;
        }

        return $data;
    }

    // Whose time an entry is. A team member can only put their own name on
    // it; a manager can pick anyone currently on the project.
    private function teamMemberFor(Request $request, Project $project, ?int $userId): int
    {
        $userId ??= $request->user()->id;

        if ($userId !== $request->user()->id) {
            abort_unless($request->user()->isManager(), 403, 'Only a manager can log time for someone else.');
            abort_unless($project->currentlyHasUser(User::findOrFail($userId)), 422, 'That person isn\'t on this project\'s team.');
        }

        return $userId;
    }

    public function update(Request $request, TimeEntry $timeEntry)
    {
        $this->authorize('update', $timeEntry);

        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'hours' => ['sometimes', 'numeric', 'min:0.25'],
            'note' => ['nullable', 'string'],
            'task_id' => [
                'nullable',
                $timeEntry->project_id
                    ? Rule::exists('tasks', 'id')->where('project_id', $timeEntry->project_id)
                    : 'exists:tasks,id',
            ],
            'billable' => ['sometimes', 'boolean'],
            'user_id' => ['sometimes', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
        ]);

        // Picking a service sets billable from it -- unless the entry is
        // already on an invoice, where billable is settled.
        if (array_key_exists('service_id', $data) && ! $timeEntry->billed) {
            $data = $this->withServiceBillable($data);
        }

        if (array_key_exists('user_id', $data)) {
            $data['user_id'] = $this->teamMemberFor($request, $timeEntry->project, (int) $data['user_id']);
        }

        $timeEntry->update($data);
        $timeEntry->load('user:id,name,avatar_path', 'task', 'service:id,name,billable');

        return $timeEntry;
    }

    public function destroy(TimeEntry $timeEntry)
    {
        $this->authorize('delete', $timeEntry);

        $timeEntry->delete();

        return response()->noContent();
    }

    // Weekly, editable grid -- used by the Timesheets screen. Same table as
    // above, just grouped by week -- editing a cell here updates the same
    // time_entries row that Time Tracking shows individually.
    public function weekly(Request $request)
    {
        $start = Carbon::parse($request->query('week_start', now()->startOfWeek()))->startOfDay();
        $end = (clone $start)->endOfWeek();

        $entries = $this->scopeToRole($request, TimeEntry::query())
            ->whereBetween('date', [$start, $end])
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->orderBy('date')
            ->get();

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'total_hours' => $entries->sum('hours'),
            'entries' => $entries,
        ];
    }

    // A Team Member only ever sees entries on projects they're (or were)
    // assigned to -- read-only history included, same rule as everywhere else.
    protected function scopeToRole(Request $request, $query)
    {
        if ($request->user()->isTeamMember()) {
            $query->whereHas('project.users', fn ($q) => $q->where('users.id', $request->user()->id));
        }

        return $query;
    }
}
