<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission('all_projects') || $task->project->everHadUser($user);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $user->hasPermission('all_projects') || ($project && $project->currentlyHasUser($user));
    }

    public function update(User $user, Task $task): bool
    {
        return $user->hasPermission('all_projects') || $task->project->currentlyHasUser($user);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasPermission('all_projects') || $task->project->currentlyHasUser($user);
    }
}
