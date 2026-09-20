<?php

namespace App\Policies\Portal;

use App\Models\Contact;
use App\Models\Project;

/**
 * The Client Hub's counterpart to App\Policies\ProjectPolicy -- same shape
 * and philosophy, but keyed to a Contact rather than a User, since Gate's
 * automatic policy resolution can't span two different Authenticatable
 * models for one Eloquent model. Invoked explicitly from Portal controllers
 * rather than through $this->authorize().
 */
class ProjectPolicy
{
    public function view(Contact $contact, Project $project): bool
    {
        return $contact->canAccessProject($project);
    }
}
