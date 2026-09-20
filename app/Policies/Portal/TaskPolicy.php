<?php

namespace App\Policies\Portal;

use App\Models\Contact;
use App\Models\Project;
use App\Models\Task;

class TaskPolicy
{
    public function view(Contact $contact, Task $task): bool
    {
        return $contact->canAccessProject($task->project);
    }

    public function create(Contact $contact, Project $project): bool
    {
        return $contact->canAccessProject($project);
    }

    public function update(Contact $contact, Task $task): bool
    {
        return $contact->canAccessProject($task->project);
    }

    // Deliberately no delete() -- clients can view/create/edit tasks but
    // not remove them (flagged to Bill as a default, not a hard requirement).
}
