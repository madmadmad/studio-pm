<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

// A client's people: the Clients permission, as the routes
// (permission:clients) already require.
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('clients');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->hasPermission('clients');
    }
}
