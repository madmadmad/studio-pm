<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    // Reading/starting/replying to threads stays governed by ProjectPolicy's
    // view()/update() (see MessageController) -- this policy only covers the
    // two actions that are author-specific rather than project-specific.

    public function update(User $user, Message $message): bool
    {
        return $user->isManager() || $message->isSender($user);
    }

    public function delete(User $user, Message $message): bool
    {
        return $user->isManager() || $message->isSender($user);
    }
}
