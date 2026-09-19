<?php

namespace App\Http\Controllers;

use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Http\Request;

class SubtaskController extends Controller
{
    public function store(Request $request, Task $task)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:255'],
        ]);

        $data['position'] = $task->subtasks()->count();

        return $task->subtasks()->create($data);
    }

    public function update(Request $request, Subtask $subtask)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:todo,in_progress,done'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $subtask->update($data);

        return $subtask;
    }

    public function destroy(Subtask $subtask)
    {
        $subtask->delete();

        return response()->noContent();
    }
}
