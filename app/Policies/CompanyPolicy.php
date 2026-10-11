<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

// Clients and their details: the Clients permission, as the routes
// (permission:clients) already require -- here so non-route callers (the
// MCP server's tools) ask the same question.
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('clients');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->hasPermission('clients');
    }
}
