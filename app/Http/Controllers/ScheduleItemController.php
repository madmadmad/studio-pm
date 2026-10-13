<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ScheduleItem;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// A project's schedule items. Same access as the rest of a project's
// working data (notes, tasks): anyone who can view the project can read
// it, anyone who can update the project can change it.
class ScheduleItemController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return $project->scheduleItems;
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        // New items go at the end of the list.
        $position = ($project->scheduleItems()->max('position') ?? -1) + 1;

        return $project->scheduleItems()->create([...$this->validated($request), 'position' => $position]);
    }

    // Builds phases from services ("Build from services"): one item per
    // service, in the order given, each starting the day after the one
    // before ends, from `starts_on`. They're ordinary items afterwards --
    // added to the end of the list, with dates to change freely.
    public function storeFromServices(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'phases' => ['required', 'array', 'min:1'],
            'phases.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'phases.*.days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $services = Service::whereKey(collect($data['phases'])->pluck('service_id'))->get()->keyBy('id');

        DB::transaction(function () use ($data, $project, $services) {
            $position = ($project->scheduleItems()->max('position') ?? -1) + 1;
            $start = Carbon::parse($data['starts_on'])->startOfDay();

            foreach ($data['phases'] as $phase) {
                $end = $start->copy()->addDays($phase['days'] - 1);
                $project->scheduleItems()->create([
                    'service_id' => $phase['service_id'],
                    'title' => $services[$phase['service_id']]->name,
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $end->toDateString(),
                    'position' => $position++,
                ]);
                $start = $end->copy()->addDay();
            }
        });

        return $project->scheduleItems()->get();
    }

    // Saves the order after a drag: `ids` is every item on the project, top
    // to bottom.
    public function reorder(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $existing = $project->scheduleItems()->pluck('id')->sort()->values()->all();
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);
        abort_unless(collect($data['ids'])->sort()->values()->all() === $existing, 422, 'The order must list every schedule item on this project exactly once.');

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $position => $id) {
                ScheduleItem::whereKey($id)->update(['position' => $position]);
            }
        });

        return $project->scheduleItems()->get();
    }

    public function update(Request $request, ScheduleItem $scheduleItem)
    {
        $this->authorize('update', $scheduleItem->project);

        $scheduleItem->update($this->validated($request, $scheduleItem));

        return $scheduleItem;
    }

    public function destroy(ScheduleItem $scheduleItem)
    {
        $this->authorize('update', $scheduleItem->project);

        $scheduleItem->delete();

        return response()->noContent();
    }

    // On update, a field left out keeps its value -- but the end date is
    // always checked against whichever start date will be saved.
    private function validated(Request $request, ?ScheduleItem $item = null): array
    {
        $required = $item ? 'sometimes' : 'required';
        $startsOn = $request->input('starts_on', $item?->starts_on?->toDateString());

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date', ...($startsOn ? ['after_or_equal:'.$startsOn] : [])],
        ], [
            'ends_on.after_or_equal' => 'The end date can\'t be before the start date.',
        ]);
    }
}
