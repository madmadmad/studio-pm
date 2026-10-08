<?php

namespace App\Policies;

use App\Models\ChatMessage;
use App\Models\User;

// As with project messages, only a message's author edits or deletes it --
// super admins included -- and only while they're still in the conversation.
// Anyone in the conversation can react.
class ChatMessagePolicy
{
    // Staff only (as ConversationPolicy).
    public function before(mixed $actor): ?bool
    {
        return $actor instanceof User ? null : false;
    }

    public function update(User $user, ChatMessage $message): bool
    {
        return ! $message->trashed() && $message->isAuthor($user) && $user->can('post', $message->conversation);
    }

    public function delete(User $user, ChatMessage $message): bool
    {
        return $this->update($user, $message);
    }

    public function react(User $user, ChatMessage $message): bool
    {
        return ! $message->trashed() && $user->can('post', $message->conversation);
    }
}
