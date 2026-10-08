<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Every channel here is staff only: the /broadcasting/auth route sits
// behind `auth:web` and `staff` (bootstrap/app.php), and each channel names
// the web guard so a Client Hub contact can never be resolved here. Who may
// listen is the same question as who may read (ConversationPolicy).

// One person's own channel: their Chat unread counts.
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->id === $id, ['guards' => ['web']]);

// A conversation's new, edited and deleted messages and reactions, and
// typing (client whispers) -- for the people in it.
Broadcast::channel('conversation.{conversation}', fn (User $user, Conversation $conversation) => $user->can('view', $conversation), ['guards' => ['web']]);

// Who's online: every signed-in staff member, whatever page they're on.
Broadcast::channel('chat.presence', fn (User $user) => $user->can('viewAny', Conversation::class)
    ? ['id' => $user->id, 'name' => $user->name]
    : false, ['guards' => ['web']]);
