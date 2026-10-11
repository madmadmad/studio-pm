<?php

namespace App\Policies;

use App\Models\User;

// Writing proposals: the Proposals permission, as the routes
// (permission:proposals) already require.
class ProposalPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('proposals');
    }
}
