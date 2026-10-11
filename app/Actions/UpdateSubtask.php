<?php

namespace App\Actions;

use App\Models\Subtask;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Changing a subtask -- ticking it off, renaming, reassigning, reordering --
// from its task's drawer (SubtaskController) or the MCP server's
// update_subtask tool.
class UpdateSubtask
{
    public static function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:todo,in_progress,done'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    // `$data` is validated against rules().
    public function handle(User $actor, Subtask $subtask, array $data): Subtask
    {
        Gate::forUser($actor)->authorize('update', $subtask->task);

        $subtask->update($data);

        return $subtask;
    }
}
