<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\ScheduleItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
            'schedule_item_id' => ['nullable', 'integer'],
            'visible_to_client' => ['sometimes', 'boolean'],
        ];
    }

    // `$data` is validated against rules().
    public function handle(User $actor, Project $project, array $data): Task
    {
        Gate::forUser($actor)->authorize('create', [Task::class, $project]);

        // A task's phase must be one of its own project's schedule items.
        if (! empty($data['schedule_item_id']) && ! ScheduleItem::whereKey($data['schedule_item_id'])->where('project_id', $project->id)->exists()) {
            throw ValidationException::withMessages(['schedule_item_id' => 'That phase isn\'t on this project\'s schedule.']);
        }

        return $project->tasks()->create($data);
    }
}
