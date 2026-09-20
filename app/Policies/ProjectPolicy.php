<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // index queries are scoped to the user's own projects at the controller level
    }

    /**
     * A team member keeps read-only visibility into a project after being
     * unassigned -- their historical time entries and notes stay legible
     * instead of disappearing. See everHadUser().
     */
    public function view(User $user, Project $project): bool
    {
        return $user->isManager() || $project->everHadUser($user);
    }

    public function create(User $user): bool
    {
        // New projects come from a Manager (usually via a proposal); a team
        // member can't be "assigned" to a project that doesn't exist yet.
        return $user->isManager();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isManager() || $project->currentlyHasUser($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isManager();
    }
}
