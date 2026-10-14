<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;

// Starring a task (the star on a project's Tasks tab): each person's own
// favorites, listed on their Profile's Tasks tab.
class TaskFavoriteController extends Controller
{
    public function store(Request $request, Task $task)
    {
        $this->authorize('view', $task);

        $request->user()->favoriteTasks()->syncWithoutDetaching([$task->id]);

        return response()->json(['is_favorite' => true]);
    }

    public function destroy(Request $request, Task $task)
    {
        $request->user()->favoriteTasks()->detach($task->id);

        return response()->json(['is_favorite' => false]);
    }
}
