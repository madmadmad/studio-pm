<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Policies\Portal\TaskPolicy;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(protected TaskPolicy $policy) {}

    public function store(Request $request, Project $project)
    {
        abort_unless($this->policy->create($request->user(), $project), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
        ]);

        return $project->tasks()->create($data);
    }

    public function update(Request $request, Task $task)
    {
        abort_unless($this->policy->update($request->user(), $task), 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:todo,in_progress,done'],
            'due_date' => ['nullable', 'date'],
        ]);

        $task->update($data);

        return $task;
    }

    // Deliberately no destroy() -- clients can't delete tasks (see TaskPolicy).
}
