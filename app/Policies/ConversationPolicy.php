<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

// Chat is staff only, and every rule here takes a staff User -- a Client
// Hub Contact never matches the type hint, so the Gate denies them before
// any of this runs. Within the staff: you see and post in the conversations
// you're in, and any of you can find and join a channel.
class ConversationPolicy
{
    // Browse channels, start a channel or a direct message.
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->isActive() && $conversation->hasMember($user);
    }

    // Read or write in it: the same people.
    public function post(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    // Channels are open to the whole studio; a direct message's people are
    // fixed (different people make a different conversation).
    public function join(User $user, Conversation $conversation): bool
    {
        return $user->isActive() && $conversation->isChannel();
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return $conversation->isChannel() && $conversation->hasMember($user);
    }
}
