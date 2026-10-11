<?php

namespace App\Http\Controllers;

use App\Actions\CreateTask;
use App\Actions\UpdateTask;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return $project->tasks;
    }

    public function store(Request $request, Project $project, CreateTask $createTask)
    {
        $this->authorize('create', [Task::class, $project]);

        return $createTask->handle($request->user(), $project, $request->validate(CreateTask::rules()));
    }

    public function update(Request $request, Task $task, UpdateTask $updateTask)
    {
        $this->authorize('update', $task);

        return $updateTask->handle($request->user(), $task, $request->validate(UpdateTask::rules()));
    }

    public function destroy(Task $task)
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->noContent();
    }
}
