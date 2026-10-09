<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

// Chat is staff only: before() turns away anyone who isn't a staff User (a
// Client Hub Contact) before any rule runs. Within the staff: you see and
// post in the conversations you're in, and any of you can find and join a
// channel.
class ConversationPolicy
{
    public function before(mixed $actor): ?bool
    {
        return $actor instanceof User ? null : false;
    }

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

    // A direct message can be closed (hidden from your sidebar); a channel
    // is left instead.
    public function close(User $user, Conversation $conversation): bool
    {
        return $conversation->isDirect() && $this->view($user, $conversation);
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return $conversation->isChannel() && $conversation->hasMember($user);
    }
}
