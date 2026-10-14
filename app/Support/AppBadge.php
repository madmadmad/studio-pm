<?php

namespace App\Support;

use App\Models\User;
use App\Services\ChatUnread;
use App\Services\UnreadMessages;

// The number on the installed app's icon (the Dock, a phone's Home
// Screen): unread chat messages plus unread client message threads. Sent
// with every push (PushAlert) and kept current by the open app.
class AppBadge
{
    public static function count(User $user): int
    {
        return (int) ChatUnread::totals($user)['unread'] + self::clientThreads($user);
    }

    // Unread client threads across the person's projects.
    public static function clientThreads(User $user): int
    {
        return (int) array_sum(UnreadMessages::countsByProject($user));
    }
}
