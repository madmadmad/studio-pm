<?php

namespace App\Actions;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Adding a subtask to the end of a task's list -- its drawer
// (SubtaskController) or the MCP server's create_subtask and create_task
// tools. Anyone who may change the task may add to it.
class CreateSubtask
{
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:255'],
        ];
    }

    // `$data` is validated against rules().
    public function handle(User $actor, Task $task, array $data): Subtask
    {
        Gate::forUser($actor)->authorize('update', $task);

        $data['position'] = $task->subtasks()->count();

        return $task->subtasks()->create($data);
    }
}
