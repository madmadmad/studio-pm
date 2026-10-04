<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('team');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('team');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission('team');
    }

    /**
     * Deactivation, not deletion -- a user's historical time entries and
     * invoices need the FK to stay intact. Also blocks self-deactivation so
     * a lone Manager can't accidentally lock themselves out.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->hasPermission('team') && $user->id !== $target->id;
    }
}
