<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Every channel here is staff only: the /broadcasting/auth route sits
// behind `auth:web` and `staff` (bootstrap/app.php), and each channel names
// the web guard so a Client Hub contact can never be resolved here.

// One person's own channel: their Chat unread counts.
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->id === $id, ['guards' => ['web']]);
