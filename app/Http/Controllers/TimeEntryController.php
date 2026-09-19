<?php

namespace App\Http\Controllers;

use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TimeEntryController extends Controller
{
    // Raw log -- used by the Time Tracking screen.
    public function index(Request $request)
    {
        return TimeEntry::query()
            ->when($request->company_id, fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->billed !== null, fn ($q) => $q->where('billed', $request->boolean('billed')))
            ->orderByDesc('date')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'task_id' => ['nullable', 'exists:tasks,id'],
            'date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.25'],
            'note' => ['nullable', 'string'],
        ]);

        $data['user_id'] = $request->user()->id;

        return TimeEntry::create($data);
    }

    public function update(Request $request, TimeEntry $timeEntry)
    {
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
        ]);

        $timeEntry->update($data);

        return $timeEntry;
    }

    public function destroy(TimeEntry $timeEntry)
    {
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

        $entries = TimeEntry::query()
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
}
