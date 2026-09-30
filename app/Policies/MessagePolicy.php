<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    // Reading/starting/replying to threads stays governed by ProjectPolicy's
    // view()/update() (see MessageController) -- this policy only covers the
    // two actions that are author-specific rather than project-specific.
    //
    // Only a message's author can edit or delete it -- managers included:
    // a message is what that person said, so nobody else rewrites or
    // removes it. (Clients are held to the same rule in the portal's
    // Portal\MessagePolicy.)

    public function update(User $user, Message $message): bool
    {
        return $message->isSender($user);
    }

    public function delete(User $user, Message $message): bool
    {
        return $message->isSender($user);
    }
}
