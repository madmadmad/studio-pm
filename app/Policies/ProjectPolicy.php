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
        return $user->hasPermission('all_projects') || $project->everHadUser($user);
    }

    public function create(User $user): bool
    {
        // New projects come from someone who manages projects (usually via a
        // proposal); nobody can be "assigned" to one that doesn't exist yet.
        return $user->hasPermission('manage_projects');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermission('all_projects') || $project->currentlyHasUser($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasPermission('manage_projects');
    }

    // The project itself -- name, status, contact, PO, who's on it -- as
    // opposed to working in it (update()).
    public function manage(User $user, Project $project): bool
    {
        return $user->hasPermission('manage_projects') && $this->view($user, $project);
    }
}
