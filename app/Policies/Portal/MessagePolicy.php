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

    // Reading, replying to or downloading from a thread: only one the
    // contact was included on. Clients can't join other threads.
    public function viewThread(Contact $contact, Message $message): bool
    {
        $thread = $message->thread();

        return $contact->canAccessProject($thread->project) && $thread->isParticipant($contact);
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
