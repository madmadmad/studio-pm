<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ScheduleItem;
use Illuminate\Http\Request;
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
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date', ...($startsOn ? ['after_or_equal:'.$startsOn] : [])],
        ], [
            'ends_on.after_or_equal' => 'The end date can\'t be before the start date.',
        ]);
    }
}
