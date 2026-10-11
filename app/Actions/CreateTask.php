<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Adding a task to a project -- from the project's Tasks tab
// (TaskController) or the MCP server's create_task tool.
class CreateTask
{
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'visible_to_client' => ['sometimes', 'boolean'],
        ];
    }

    // `$data` is validated against rules().
    public function handle(User $actor, Project $project, array $data): Task
    {
        Gate::forUser($actor)->authorize('create', [Task::class, $project]);

        return $project->tasks()->create($data);
    }
}
