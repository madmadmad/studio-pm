<?php

namespace App\Policies\Portal;

use App\Models\Contact;
use App\Models\Project;

class MessagePolicy
{
    public function view(Contact $contact, Project $project): bool
    {
        return $contact->canAccessProject($project);
    }

    public function create(Contact $contact, Project $project): bool
    {
        return $contact->canAccessProject($project);
    }
}
