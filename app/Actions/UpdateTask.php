<?php

namespace App\Actions;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Changing a task -- its drawer (TaskController) or the MCP server's
// update_task tool.
class UpdateTask
{
    public static function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:todo,in_progress,done'],
            'due_date' => ['nullable', 'date'],
            'visible_to_client' => ['sometimes', 'boolean'],
        ];
    }

    // `$data` is validated against rules().
    public function handle(User $actor, Task $task, array $data): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        $task->update($data);

        return $task;
    }
}
