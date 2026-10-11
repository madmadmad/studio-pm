<?php

namespace App\Http\Controllers;

use App\Actions\CreateSubtask;
use App\Actions\UpdateSubtask;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Http\Request;

class SubtaskController extends Controller
{
    public function store(Request $request, Task $task, CreateSubtask $createSubtask)
    {
        $this->authorize('update', $task);

        return $createSubtask->handle($request->user(), $task, $request->validate(CreateSubtask::rules()));
    }

    public function update(Request $request, Subtask $subtask, UpdateSubtask $updateSubtask)
    {
        $this->authorize('update', $subtask->task);

        return $updateSubtask->handle($request->user(), $subtask, $request->validate(UpdateSubtask::rules()));
    }

    public function destroy(Subtask $subtask)
    {
        $this->authorize('update', $subtask->task);

        $subtask->delete();

        return response()->noContent();
    }
}
