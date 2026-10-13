<?php

namespace App\Actions;

use App\Models\ScheduleItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
            'schedule_item_id' => ['nullable', 'integer'],
            'visible_to_client' => ['sometimes', 'boolean'],
        ];
    }

    // `$data` is validated against rules().
    public function handle(User $actor, Task $task, array $data): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        // A task's phase must be one of its own project's schedule items.
        if (! empty($data['schedule_item_id']) && ! ScheduleItem::whereKey($data['schedule_item_id'])->where('project_id', $task->project_id)->exists()) {
            throw ValidationException::withMessages(['schedule_item_id' => 'That phase isn\'t on this project\'s schedule.']);
        }

        $task->update($data);

        return $task;
    }
}
