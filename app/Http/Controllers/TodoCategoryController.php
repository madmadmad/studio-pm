<?php

namespace App\Http\Controllers;

use App\Models\TodoCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// The groups on a person's own to-do list (My Work → To-do): theirs to
// name, color, order, fold away and delete. Like the to-dos, anyone
// else's is a 404.
class TodoCategoryController extends Controller
{
    // A new one goes after the rest, in the next default color in turn.
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['sometimes', 'regex:'.TodoCategory::COLOR_PATTERN],
        ]);
        $user = $request->user();
        $count = TodoCategory::where('user_id', $user->id)->count();

        $category = new TodoCategory([
            'name' => trim($data['name']),
            'color' => strtoupper($data['color'] ?? TodoCategory::DEFAULT_COLORS[$count % count(TodoCategory::DEFAULT_COLORS)]),
            'position' => (TodoCategory::where('user_id', $user->id)->max('position') ?? -1) + 1,
        ]);
        $category->user()->associate($user)->save();

        return $category;
    }

    // Renamed, recolored, or folded / unfolded.
    public function update(Request $request, TodoCategory $todoCategory)
    {
        $this->own($request, $todoCategory);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'color' => ['sometimes', 'regex:'.TodoCategory::COLOR_PATTERN],
            'collapsed' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            abort_if($data['name'] === '', 422, 'A group needs a name.');
        }
        if (isset($data['color'])) {
            $data['color'] = strtoupper($data['color']);
        }
        $todoCategory->update($data);

        return $todoCategory;
    }

    // Saves their order after a drag: `ids` top to bottom.
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $userId = $request->user()->id;

        DB::transaction(function () use ($data, $userId) {
            foreach ($data['ids'] as $position => $id) {
                TodoCategory::where('user_id', $userId)->whereKey($id)->update(['position' => $position]);
            }
        });

        return TodoController::listFor($userId);
    }

    // Its to-dos stay, in no group (the foreign key sets them loose).
    public function destroy(Request $request, TodoCategory $todoCategory)
    {
        $this->own($request, $todoCategory);
        $todoCategory->delete();

        return response()->noContent();
    }

    private function own(Request $request, TodoCategory $category): void
    {
        abort_unless($category->user_id === $request->user()->id, 404);
    }
}
