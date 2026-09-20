<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;

class TimeEntryPolicy
{
    public function view(User $user, TimeEntry $timeEntry): bool
    {
        return $user->isManager() || $timeEntry->project->everHadUser($user);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $user->isManager() || ($project && $project->currentlyHasUser($user));
    }

    /**
     * A team member can log and adjust their own hours on a project they're
     * still assigned to, but not someone else's timesheet -- shared project
     * access doesn't mean shared editing rights over each other's time.
     */
    public function update(User $user, TimeEntry $timeEntry): bool
    {
        return $user->isManager()
            || ($timeEntry->user_id === $user->id && $timeEntry->project->currentlyHasUser($user));
    }

    public function delete(User $user, TimeEntry $timeEntry): bool
    {
        return $this->update($user, $timeEntry);
    }
}
