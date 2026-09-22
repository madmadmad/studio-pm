<?php

namespace App\Policies\Portal;

use App\Models\Contact;
use App\Models\Message;
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

    // No manager-style override on the client side -- a contact can only
    // ever touch their own messages.
    public function update(Contact $contact, Message $message): bool
    {
        return $message->isSender($contact);
    }

    public function delete(Contact $contact, Message $message): bool
    {
        return $message->isSender($contact);
    }
}
